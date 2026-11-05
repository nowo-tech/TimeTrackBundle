<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Event\TimesheetTransitionEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_int;
use function mb_strlen;

/**
 * Since 1.13: the timesheet state machine (contract §5.2–§5.7).
 *
 * ```
 * open ─submit (owner)─▶ submitted ─approve (approver)─▶ approved [locked]
 *  ▲ ◀──withdraw (owner)──┘   └─reject (approver, comment)─▶ rejected ─submit─▶ submitted
 *  └──── reopen (approver / reopen_roles, reason) ◀── approved / auto-locked
 * open ─auto-lock (system)─▶ open [locked: auto]
 * ```
 *
 * Every transition runs in one transaction with its audit row (hash chain) and, on approve / reopen, the ratified
 * overtime line or its reversal; the timesheet's optimistic `version` makes concurrent decisions fail (`conflict`).
 * Approve recomputes the entries hash: a change since submit answers `stale`. Nobody approves their own timesheet.
 * Other users' timesheets the actor may not act on answer `not_found` (no existence oracle). Events fire after commit.
 */
final readonly class TimesheetService
{
    /** Shortest reject / reopen comment. */
    public const COMMENT_MIN_LENGTH = 10;

    private ClockInterface $clock;

    /**
     * @param bool $enabled `timesheets.enabled`
     * @param TimesheetPeriodKind $periodKind `timesheets.period`
     * @param bool $submitRequiresPeriodEnd `timesheets.submit_requires_period_end`
     * @param bool $ratifyOnApproval `working_time.overtime.ratify_on_approval`
     */
    public function __construct(
        private TimesheetRepositoryInterface $timesheets,
        private TimesheetEventRepositoryInterface $events,
        private TimesheetAuditTrail $auditTrail,
        private TimesheetAccessGuard $accessGuard,
        private TimesheetSnapshotBuilder $snapshots,
        private TimesheetTimezoneResolver $timezoneResolver,
        private ActiveTimerRepositoryInterface $activeTimers,
        private ?BalanceLedger $ledger = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        private bool $enabled = true,
        private TimesheetPeriodKind $periodKind = TimesheetPeriodKind::Week,
        private bool $submitRequiresPeriodEnd = false,
        private bool $ratifyOnApproval = true,
        private LoggerInterface $logger = new NullLogger(),
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Whether timesheets are on.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The configured period kind.
     */
    public function periodKind(): TimesheetPeriodKind
    {
        return $this->periodKind;
    }

    /**
     * The access guard (views and controllers).
     */
    public function accessGuard(): TimesheetAccessGuard
    {
        return $this->accessGuard;
    }

    /**
     * The period of `current`, `previous`, a key (`2026-W45` / `2026-11`) or null (current) for the user and scope.
     * Keys must lie in the period window ({@see Period::fromKey()}) and start no later than the next period.
     *
     * @throws TimesheetException `not_found` for an invalid key, or one outside that range
     */
    public function period(UserInterface $user, string $scopeKey, ?string $key): Period
    {
        $current = Period::containing($this->periodKind, $this->today($user, $scopeKey));
        try {
            $period = match ($key) {
                null, '', 'current' => $current,
                'previous'          => $current->previous(),
                default             => Period::fromKey($this->periodKind, $key),
            };
        } catch (InvalidArgumentException $e) {
            throw new TimesheetException('not_found', $e);
        }
        if ($period->start > $current->next()->start) {
            throw new TimesheetException('not_found');
        }

        return $period;
    }

    /**
     * The stored timesheet of the period for an authorised viewer; for the owner without a row, the row is created
     * (open) when the period is the current or the previous one (contract §3.2: created lazily on first view), and any
     * other period answers an unstored open draft ({@see Timesheet::isDraft()}): a view never creates rows for
     * arbitrary periods. `not_found` when the viewer may not see it or (non-owner) it does not exist.
     *
     * @throws TimesheetException
     */
    public function view(UserInterface $viewer, string $ownerId, string $scopeKey, Period $period): Timesheet
    {
        $timesheet = $this->peek($viewer, $ownerId, $scopeKey, $period);
        if (!$timesheet->isDraft()) {
            return $timesheet;
        }
        $current = Period::containing($this->periodKind, $this->today($viewer, $scopeKey));

        return $period->start === $current->start || $period->start === $current->previous()->start ? $this->create($viewer, $scopeKey, $period) : $timesheet;
    }

    /**
     * Like {@see self::view()} but never writes: the owner's missing row is always an unstored open draft (`GET me`).
     *
     * @throws TimesheetException
     */
    public function peek(UserInterface $viewer, string $ownerId, string $scopeKey, Period $period): Timesheet
    {
        if (!$this->enabled || !$this->accessGuard->canView($viewer, $ownerId, $scopeKey)) {
            throw new TimesheetException('not_found');
        }
        $timesheet = $this->timesheets->findForPeriod($ownerId, $scopeKey, $this->periodKind, $period->start);
        if ($timesheet instanceof Timesheet) {
            return $timesheet;
        }
        if (!$this->accessGuard->isOwner($viewer, $ownerId)) {
            throw new TimesheetException('not_found');
        }

        return (new Timesheet($viewer, UserIdResolver::getId($viewer), $scopeKey, $period, $this->timezoneResolver->resolve($viewer, $scopeKey), $this->clock->now()))->markDraft();
    }

    /**
     * The snapshot (entries, totals, balance) of a timesheet as of now.
     */
    public function snapshot(Timesheet $timesheet): TimesheetSnapshot
    {
        $owner = $timesheet->getUser();

        return $this->snapshots->build($owner instanceof UserInterface ? $owner : null, $timesheet->getOwnerRef(), $timesheet->getScopeKey(), $timesheet->getPeriod(), $timesheet->getTimezone());
    }

    /**
     * The audit rows of a timesheet, in chain order.
     *
     * @return list<TimesheetEvent>
     */
    public function events(Timesheet $timesheet): array
    {
        return $this->events->findByTimesheet($timesheet->getId());
    }

    /**
     * The approval queue of an approver in a scope (only `reviewableUserIds` of a scope of theirs).
     *
     * @return list<Timesheet>
     */
    public function queue(UserInterface $approver, string $scopeKey, ?TimesheetStatus $status = TimesheetStatus::Submitted, int $limit = 100): array
    {
        if (!$this->enabled) {
            return [];
        }
        $owners = $this->accessGuard->reviewableUserIds($approver, $scopeKey);

        return $owners === [] ? [] : $this->timesheets->findQueue($scopeKey, $owners, $status, $limit);
    }

    /**
     * The timesheet with this id when the actor may at least view it.
     *
     * @throws TimesheetException `not_found`
     */
    public function find(UserInterface $actor, string $timesheetId): Timesheet
    {
        $timesheet = $this->enabled ? $this->timesheets->find($timesheetId) : null;
        if (!$timesheet instanceof Timesheet || !$this->accessGuard->canView($actor, $timesheet->getOwnerRef(), $timesheet->getScopeKey())) {
            throw new TimesheetException('not_found');
        }

        return $timesheet;
    }

    /**
     * Submits the owner's period (open or rejected; an auto-locked open period too).
     *
     * @throws TimesheetException
     */
    public function submit(UserInterface $owner, string $scopeKey, Period $period, AuditClient $client = AuditClient::Web): Timesheet
    {
        $ownerId = UserIdResolver::getId($owner);
        if (!$this->enabled || !$this->accessGuard->canSubmit($owner, $ownerId, $scopeKey)) {
            throw new TimesheetException('not_found');
        }
        $timesheet = $this->timesheets->findForPeriod($ownerId, $scopeKey, $this->periodKind, $period->start) ?? $this->create($owner, $scopeKey, $period);
        if (!$timesheet->getStatus()->isEditable()) {
            throw new TimesheetException('invalid_transition');
        }
        $today = $this->today($owner, $scopeKey);
        if ($today < $period->start) {
            throw new TimesheetException('not_started');
        }
        if ($this->submitRequiresPeriodEnd && $today <= $period->end) {
            throw new TimesheetException('not_ended');
        }
        $timer = $this->activeTimers->findByUserId($ownerId);
        if ($timer instanceof ActiveTimer && $timesheet->covers($timer->getStartedAt()) && $this->timerScope($owner, $timer) === $scopeKey) {
            throw new TimesheetException('timer_running');
        }

        $snapshot = $this->snapshot($timesheet);
        $now      = $this->clock->now();

        return $this->transition($timesheet, TimesheetAction::Submitted, $ownerId, TimesheetActorRole::Owner, $client, null, static function (Timesheet $t) use ($now, $ownerId, $snapshot): array {
            $t->applySubmit($now, $ownerId, $snapshot->entriesHash, $snapshot->totals);

            return [$snapshot->entriesHash, $snapshot->totals];
        });
    }

    /**
     * Withdraws the owner's submitted period back to open.
     *
     * @throws TimesheetException
     */
    public function withdraw(UserInterface $owner, string $scopeKey, Period $period, AuditClient $client = AuditClient::Web): Timesheet
    {
        $ownerId = UserIdResolver::getId($owner);
        if (!$this->enabled || !$this->accessGuard->canSubmit($owner, $ownerId, $scopeKey, TimesheetAccessGuard::WITHDRAW)) {
            throw new TimesheetException('not_found');
        }
        $timesheet = $this->timesheets->findForPeriod($ownerId, $scopeKey, $this->periodKind, $period->start);
        if (!$timesheet instanceof Timesheet || $timesheet->getStatus() !== TimesheetStatus::Submitted) {
            throw new TimesheetException('invalid_transition');
        }
        $now = $this->clock->now();

        return $this->transition($timesheet, TimesheetAction::Withdrawn, $ownerId, TimesheetActorRole::Owner, $client, null, static function (Timesheet $t) use ($now): array {
            $t->applyWithdraw($now);

            return [null, null];
        });
    }

    /**
     * Approves a submitted timesheet: hash re-checked (`stale`), hard lock, final snapshot and (with
     * `ratify_on_approval`) one `overtime_ratified` ledger line with the period's overtime.
     *
     * @throws TimesheetException
     */
    public function approve(UserInterface $approver, string $timesheetId, AuditClient $client = AuditClient::Web): Timesheet
    {
        $timesheet = $this->decidable($approver, $timesheetId, TimesheetAccessGuard::APPROVE);
        if ($timesheet->getStatus() !== TimesheetStatus::Submitted) {
            throw new TimesheetException('invalid_transition');
        }
        $snapshot = $this->snapshot($timesheet);
        if ($snapshot->entriesHash !== $timesheet->getEntriesHash()) {
            throw new TimesheetException('stale');
        }
        $actorRef = UserIdResolver::getId($approver);
        $now      = $this->clock->now();
        $ratified = [];
        $owner    = $timesheet->getUser();
        $overtime = $snapshot->totals['overtimeSeconds'] ?? null;

        $result = $this->transition($timesheet, TimesheetAction::Approved, $actorRef, TimesheetActorRole::Approver, $client, null, function (Timesheet $t) use ($now, $actorRef, $snapshot, $owner, $overtime, &$ratified): array {
            $t->applyApprove($now, $actorRef, $snapshot->entriesHash, $snapshot->totals);
            if ($this->ratifyOnApproval && $this->ledger instanceof BalanceLedger && is_int($overtime) && $overtime > 0) {
                $ratified[] = $this->ledger->ratify($owner instanceof UserInterface ? $owner : null, $t->getOwnerRef(), $t, $overtime, $actorRef);
            }

            return [$snapshot->entriesHash, $snapshot->totals];
        });
        $this->dispatchLines($ratified);

        return $result;
    }

    /**
     * Approves several timesheets, each checked and audited on its own.
     *
     * @param list<string> $timesheetIds
     *
     * @return array<string, string> timesheet id => `approved` or the error code
     */
    public function approveMany(UserInterface $approver, array $timesheetIds, AuditClient $client = AuditClient::Web): array
    {
        $outcomes = [];
        foreach ($timesheetIds as $id) {
            try {
                $this->approve($approver, $id, $client);
                $outcomes[$id] = 'approved';
            } catch (TimesheetException $e) {
                $outcomes[$id] = $e->errorCode();
            }
        }

        return $outcomes;
    }

    /**
     * Rejects a submitted timesheet with a comment (10–1000 characters, shown to the owner until the next submit).
     *
     * @throws TimesheetException
     */
    public function reject(UserInterface $approver, string $timesheetId, string $comment, AuditClient $client = AuditClient::Web): Timesheet
    {
        $timesheet = $this->decidable($approver, $timesheetId, TimesheetAccessGuard::REJECT);
        $comment   = $this->comment($comment);
        if ($timesheet->getStatus() !== TimesheetStatus::Submitted) {
            throw new TimesheetException('invalid_transition');
        }
        $actorRef = UserIdResolver::getId($approver);
        $now      = $this->clock->now();

        return $this->transition($timesheet, TimesheetAction::Rejected, $actorRef, TimesheetActorRole::Approver, $client, $comment, static function (Timesheet $t) use ($now, $actorRef, $comment): array {
            $t->applyReject($now, $actorRef, $comment);

            return [null, null];
        });
    }

    /**
     * Reopens a locked period with a reason: unlocked, status open, every event kept, ratified overtime reversed.
     *
     * @throws TimesheetException
     */
    public function reopen(UserInterface $actor, string $timesheetId, string $reason, AuditClient $client = AuditClient::Web): Timesheet
    {
        $timesheet = $this->enabled ? $this->timesheets->find($timesheetId) : null;
        if (!$timesheet instanceof Timesheet || !$this->accessGuard->canReopen($actor, $timesheet->getOwnerRef(), $timesheet->getScopeKey())) {
            throw new TimesheetException('not_found');
        }
        $reason = $this->comment($reason);
        if (!$timesheet->isLocked()) {
            throw new TimesheetException('invalid_transition');
        }
        $actorRef = UserIdResolver::getId($actor);
        $role     = $this->accessGuard->roleOf($actor, $timesheet->getOwnerRef(), $timesheet->getScopeKey());
        $now      = $this->clock->now();
        $owner    = $timesheet->getUser();
        $reversed = [];

        $result = $this->transition($timesheet, TimesheetAction::Reopened, $actorRef, $role, $client, $reason, function (Timesheet $t) use ($now, $actorRef, $reason, $owner, &$reversed): array {
            $t->applyReopen($now, $actorRef, $reason);
            $reversed = $this->ledger?->reverseRatified($owner instanceof UserInterface ? $owner : null, $t, $actorRef, $reason) ?? [];

            return [null, null];
        });
        $this->dispatchLines($reversed);

        return $result;
    }

    /**
     * Auto-locks the owner's period (system; creates the row when missing). Returns null when it was already locked.
     */
    public function autoLock(UserInterface $owner, string $scopeKey, Period $period): ?Timesheet
    {
        $ownerId   = UserIdResolver::getId($owner);
        $timesheet = $this->timesheets->findForPeriod($ownerId, $scopeKey, $this->periodKind, $period->start) ?? $this->create($owner, $scopeKey, $period);
        if ($timesheet->isLocked()) {
            return null;
        }
        $now = $this->clock->now();

        return $this->transition($timesheet, TimesheetAction::AutoLocked, null, TimesheetActorRole::System, AuditClient::System, null, static function (Timesheet $t) use ($now): array {
            $t->applyLock($now, TimesheetLockReason::Auto);

            return [null, null];
        });
    }

    /**
     * Local date of today for the user in the scope.
     */
    public function today(UserInterface $user, string $scopeKey): string
    {
        // @igor-ignore - reads the clock (a new immutable value); no worker state.
        return $this->clock->now()->setTimezone(new DateTimeZone($this->timezoneResolver->resolve($user, $scopeKey)))->format('Y-m-d');
    }

    /**
     * Runs one transition: the change, the audit row and the head move in one transaction; the event after commit.
     *
     * @param callable(Timesheet): array{0: string|null, 1: array<string, mixed>|null} $change
     *
     * @throws TimesheetException `conflict` when a concurrent change won (optimistic lock or audit sequence)
     */
    private function transition(Timesheet $timesheet, TimesheetAction $action, ?string $actorRef, TimesheetActorRole $role, AuditClient $client, ?string $comment, callable $change): Timesheet
    {
        $from = $timesheet->getStatus();
        try {
            // @igor-ignore - the closure runs once inside this call (transaction body), it is not stored.
            $totals = $this->timesheets->transactional(function () use ($timesheet, $action, $from, $actorRef, $role, $client, $comment, $change): ?array {
                [$hash, $totals] = $change($timesheet);
                // @igor-ignore - I/O delegate (audit row append); no worker state.
                $this->auditTrail->append($timesheet, $action, $from, $timesheet->getStatus(), $actorRef, $role, $client, $this->clock->now(), $comment, $hash, $totals);
                $this->timesheets->save($timesheet);

                return $totals;
            });
        } catch (OptimisticLockException|UniqueConstraintViolationException $e) {
            throw new TimesheetException('conflict', $e);
        }

        $this->eventDispatcher?->dispatch(
            new TimesheetTransitionEvent($timesheet->getId(), $timesheet->getOwnerRef(), $timesheet->getScopeKey(), $timesheet->getPeriod()->key, $from, $timesheet->getStatus(), $actorRef, $role, $client, $totals, $comment !== null),
            match ($action) {
                TimesheetAction::Submitted => TimeTrackEvents::TIMESHEET_SUBMITTED,
                TimesheetAction::Withdrawn => TimeTrackEvents::TIMESHEET_WITHDRAWN,
                TimesheetAction::Approved  => TimeTrackEvents::TIMESHEET_APPROVED,
                TimesheetAction::Rejected  => TimeTrackEvents::TIMESHEET_REJECTED,
                TimesheetAction::Reopened  => TimeTrackEvents::TIMESHEET_REOPENED,
                default                    => TimeTrackEvents::TIMESHEET_AUTO_LOCKED,
            },
        );
        $this->logger->info('TimeTrack timesheet transition.', ['bundle' => 'time_track', 'action' => 'timesheet_' . $action->value, 'client' => $client->value]);

        return $timesheet;
    }

    /**
     * A timesheet the approver may decide on (`not_found` otherwise).
     */
    private function decidable(UserInterface $approver, string $timesheetId, string $action): Timesheet
    {
        $timesheet = $this->enabled ? $this->timesheets->find($timesheetId) : null;
        if (!$timesheet instanceof Timesheet || !$this->accessGuard->canDecide($approver, $timesheet->getOwnerRef(), $timesheet->getScopeKey(), $action)) {
            throw new TimesheetException('not_found');
        }

        return $timesheet;
    }

    /**
     * A cleaned comment of 10–1000 characters.
     */
    private function comment(string $comment): string
    {
        $clean  = NoteSanitizer::clean($comment);
        $length = mb_strlen($clean);
        if ($length < self::COMMENT_MIN_LENGTH || $length > TimesheetAuditTrail::COMMENT_MAX_LENGTH) {
            throw new TimesheetException('comment_required');
        }

        return $clean;
    }

    private function create(UserInterface $owner, string $scopeKey, Period $period): Timesheet
    {
        $timesheet = new Timesheet($owner, UserIdResolver::getId($owner), $scopeKey, $period, $this->timezoneResolver->resolve($owner, $scopeKey), $this->clock->now());
        try {
            $this->timesheets->save($timesheet);
        } catch (UniqueConstraintViolationException $e) {
            // Two first views at once: the other request created the row.
            throw new TimesheetException('conflict', $e);
        }

        return $timesheet;
    }

    private function timerScope(UserInterface $owner, ActiveTimer $timer): ?string
    {
        return $this->scopeProvider instanceof TimesheetScopeProviderInterface ? $this->scopeProvider->scopeKeyForTask($owner, $timer->getTaskId(), $timer->getBoardIdSnapshot()) : null;
    }

    /**
     * @param list<BalanceLedgerLine> $lines
     */
    private function dispatchLines(array $lines): void
    {
        foreach ($lines as $line) {
            $this->ledger?->dispatch($line);
        }
    }
}
