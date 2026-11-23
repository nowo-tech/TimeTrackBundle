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
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;
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
 * Approve recomputes the entries hash: a change since submit answers `stale`.
 *
 * Since 1.27.0 (Beacon spec 239) a transition holds the owner's write lock (the lock every entry and timer writer of
 * that user takes) and then the timesheet row's lock, reads the timesheet again under them (a decision another request
 * committed meanwhile answers `invalid_transition`, not a lost update), and only then computes the entries hash and the
 * totals: an entry written at the same moment is either committed before the hash is taken, or waits and then finds
 * the period submitted. Up to 1.26 submit hashed the entries before its transaction, so a concurrent entry could end
 * up in a submitted period without being in its hash (the approval then answered `stale`). A first view or submit that
 * creates the period's row reads it again under the owner's lock, so two at once share one row. Nobody approves their own timesheet.
 * Other users' timesheets the actor may not act on answer `not_found` (no existence oracle). Events fire after commit.
 */
final readonly class TimesheetService
{
    private UserReferenceResolverInterface $userReferences;

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
        ?UserReferenceResolverInterface $userReferences = null,
        // Since 1.27.0 (Beacon spec 239): the owner's write lock (stores with UserSerialisedTimeEntryRepositoryInterface),
        // taken by every transition and first creation, as the entry writers take it (null = no lock, the 1.26 behaviour).
        private ?TimeEntryRepositoryInterface $entries = null,
    ) {
        $this->clock          = $clock ?? new NativeClock();
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
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

        return (new Timesheet($this->userReferences->reference($viewer), UserIdResolver::getId($viewer), $scopeKey, $period, $this->timezoneResolver->resolve($viewer, $scopeKey), $this->clock->now()))->markDraft();
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

        $now = $this->clock->now();

        return $this->transition($timesheet, TimesheetAction::Submitted, $ownerId, TimesheetActorRole::Owner, $client, null, function (Timesheet $t) use ($now, $ownerId): array {
            // Since 1.27.0: the hash and totals of the entries as committed when the owner's and the row's locks are held.
            $snapshot = $this->snapshot($t);
            $t->applySubmit($now, $ownerId, $snapshot->entriesHash, $snapshot->totals);

            return [$snapshot->entriesHash, $snapshot->totals];
        }, static fn (Timesheet $t): bool => $t->getStatus()->isEditable());
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
        }, static fn (Timesheet $t): bool => $t->getStatus() === TimesheetStatus::Submitted);
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
        $actorRef = UserIdResolver::getId($approver);
        $now      = $this->clock->now();
        $ratified = [];
        $owner    = $timesheet->getUser();

        $result = $this->transition($timesheet, TimesheetAction::Approved, $actorRef, TimesheetActorRole::Approver, $client, null, function (Timesheet $t) use ($now, $actorRef, $owner, &$ratified): array {
            // Since 1.27.0: the hash re-check on the entries as committed under the owner's and the row's locks.
            $snapshot = $this->snapshot($t);
            if ($snapshot->entriesHash !== $t->getEntriesHash()) {
                throw new TimesheetException('stale');
            }
            $overtime = $snapshot->totals['overtimeSeconds'] ?? null;
            $ratified = [];
            $t->applyApprove($now, $actorRef, $snapshot->entriesHash, $snapshot->totals);
            if ($this->ratifyOnApproval && $this->ledger instanceof BalanceLedger && is_int($overtime) && $overtime > 0) {
                $ratified[] = $this->ledger->ratify($owner instanceof UserInterface ? $owner : null, $t->getOwnerRef(), $t, $overtime, $actorRef);
            }

            return [$snapshot->entriesHash, $snapshot->totals];
        }, static fn (Timesheet $t): bool => $t->getStatus() === TimesheetStatus::Submitted);
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
     * Since 1.39.0: the timesheet an approve / reject / reopen dialog acts on, refused exactly as {@see approve()} /
     * {@see reject()} / {@see reopen()} refuse it before the comment is read (`not_found` without the right,
     * `invalid_transition` in the wrong status), so the dialog's form is only served to whoever may post it.
     *
     * @throws TimesheetException
     */
    public function decisionTarget(UserInterface $actor, string $timesheetId, string $action): Timesheet
    {
        if ($action === TimesheetAccessGuard::REOPEN) {
            $timesheet = $this->enabled ? $this->timesheets->find($timesheetId) : null;
            if (!$timesheet instanceof Timesheet || !$this->accessGuard->canReopen($actor, $timesheet->getOwnerRef(), $timesheet->getScopeKey())) {
                throw new TimesheetException('not_found');
            }
            if (!$timesheet->isLocked()) {
                throw new TimesheetException('invalid_transition');
            }

            return $timesheet;
        }
        if ($action !== TimesheetAccessGuard::APPROVE && $action !== TimesheetAccessGuard::REJECT) {
            throw new TimesheetException('invalid_request');
        }
        $timesheet = $this->decidable($actor, $timesheetId, $action);
        if ($timesheet->getStatus() !== TimesheetStatus::Submitted) {
            throw new TimesheetException('invalid_transition');
        }

        return $timesheet;
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
        }, static fn (Timesheet $t): bool => $t->getStatus() === TimesheetStatus::Submitted);
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
        }, static fn (Timesheet $t): bool => $t->isLocked());
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
        try {
            return $this->transition($timesheet, TimesheetAction::AutoLocked, null, TimesheetActorRole::System, AuditClient::System, null, static function (Timesheet $t) use ($now): array {
                $t->applyLock($now, TimesheetLockReason::Auto);

                return [null, null];
            }, static fn (Timesheet $t): bool => !$t->isLocked());
        } catch (TimesheetException $exception) {
            if ($exception->errorCode() !== 'invalid_transition') {
                throw $exception;
            }

            return null; // Locked by a concurrent request meanwhile.
        }
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
     * Since 1.27.0 (Beacon spec 239): under the owner's write lock, the timesheet row is locked and read again first
     * (stores with {@see DoctrineOrmTimesheetRepository}), `$expect` re-checks its status on that state (else
     * `invalid_transition`), and `$change` (which computes any hash or totals) runs only then.
     *
     * @param callable(Timesheet): array{0: string|null, 1: array<string, mixed>|null} $change
     * @param (callable(Timesheet): bool)|null $expect Whether the transition is still allowed on the state read under the lock
     *
     * @throws TimesheetException `conflict` when a concurrent change won (optimistic lock or audit sequence), `invalid_transition` when a concurrent decision changed the status first
     */
    private function transition(Timesheet $timesheet, TimesheetAction $action, ?string $actorRef, TimesheetActorRole $role, AuditClient $client, ?string $comment, callable $change, ?callable $expect = null): Timesheet
    {
        $from  = $timesheet->getStatus();
        $owner = $timesheet->getUser();
        // @igor-ignore - the closure runs once inside this call (transaction body), it is not stored.
        $unit = function () use (&$timesheet, $action, &$from, $actorRef, $role, $client, $comment, $change, $expect): ?array {
            $locked = $this->timesheets instanceof DoctrineOrmTimesheetRepository ? $this->timesheets->lockForTransition($timesheet->getId()) : $timesheet;
            if (!$locked instanceof Timesheet) {
                throw new TimesheetException('not_found');
            }
            if ($expect !== null && !$expect($locked)) {
                throw new TimesheetException('invalid_transition');
            }
            $timesheet       = $locked;
            $from            = $locked->getStatus();
            [$hash, $totals] = $change($locked);
            // @igor-ignore - I/O delegate (audit row append); no worker state.
            $this->auditTrail->append($locked, $action, $from, $locked->getStatus(), $actorRef, $role, $client, $this->clock->now(), $comment, $hash, $totals);
            $this->timesheets->save($locked);

            return $totals;
        };
        try {
            $totals = $this->inOwnerLock($owner, fn (): ?array => $this->timesheets->transactional($unit));
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
        try {
            // Since 1.27.0 (Beacon spec 239): read again under the owner's lock, so two first views (or a view and a
            // submit) at once share one row instead of failing on the unique key (which closed the entity manager).
            // @igor-ignore - the closure runs once inside this call, it is not stored.
            return $this->inOwnerLock($owner, function () use ($owner, $scopeKey, $period): Timesheet {
                $existing = $this->entries instanceof UserSerialisedTimeEntryRepositoryInterface ? $this->timesheets->findForPeriod(UserIdResolver::getId($owner), $scopeKey, $this->periodKind, $period->start) : null;
                if ($existing instanceof Timesheet) {
                    return $existing;
                }
                $timesheet = new Timesheet($this->userReferences->reference($owner), UserIdResolver::getId($owner), $scopeKey, $period, $this->timezoneResolver->resolve($owner, $scopeKey), $this->clock->now());
                $this->timesheets->save($timesheet);

                return $timesheet;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two first views at once on a store without the owner's lock: the other request created the row.
            throw new TimesheetException('conflict', $e);
        }
    }

    /**
     * Since 1.27.0: runs the operation holding the owner's write lock when the entry store serialises users (the lock
     * every entry and timer writer of that user takes); as it is otherwise (a former person's timesheet, other stores).
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function inOwnerLock(?object $owner, callable $operation): mixed
    {
        return $owner !== null && $this->entries instanceof UserSerialisedTimeEntryRepositoryInterface ? $this->entries->inUserTransaction($owner, $operation) : $operation();
    }

    private function timerScope(UserInterface $owner, ActiveTimer $timer): ?string
    {
        // Since 1.36.0: a timer without a task carries the scope it was started in.
        if (!$timer->hasTask()) {
            return $timer->getScopeKey();
        }

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
