<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\BalanceLedgerLineAddedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function abs;
use function mb_substr;

/**
 * Since 1.13: the balance ledger service (contract §4.7). Lines are append-only and chained per owner and scope; a
 * correction is a reversal plus a new line.
 *
 * - `opening`, `adjustment`, `overtime_paid`, `overtime_compensated`, `reversal`: holders of `adjust_balance` for that
 *   owner and scope ({@see TimesheetAccessGuard::canAdjustBalance()}: approvers, never the owner); reason required for
 *   opening, adjustment, overtime paid and reversal (at most 500 characters).
 * - `overtime_ratified`: only the timesheet approval ({@see self::ratify()}), never {@see self::add()}.
 * - Refused when the effective date is in a locked period (except the approval's own ratification); `opening` once per
 *   owner and scope unless reversed; `overtime_paid` is negative, `overtime_compensated` positive.
 */
final readonly class BalanceLedger
{
    /** Longest reason, in code points. */
    public const REASON_MAX_LENGTH = 500;

    /** Largest absolute movement of one line (one year of seconds). */
    public const MAX_SECONDS = 31_622_400;

    private ClockInterface $clock;

    /**
     * Receives the store, the guards and the event dispatcher.
     */
    public function __construct(
        private BalanceLedgerRepositoryInterface $lines,
        private TimesheetAccessGuard $accessGuard,
        private PeriodLockGuard $lockGuard,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Appends a line for `$owner` in the scope on behalf of `$actor`.
     *
     * @param string $effectiveDate Local date the movement applies to (Y-m-d)
     * @param string|null $reversesId The line a reversal cancels (its seconds are the opposite of that line's)
     *
     * @throws LedgerRefusedException When a rule refuses the line, or `conflict` when a concurrent line won (nothing written)
     * @throws PeriodLockedException When the effective date is in a locked period
     */
    public function add(UserInterface $actor, UserInterface $owner, string $scopeKey, LedgerKind $kind, int $seconds, string $effectiveDate, ?string $reason, ?string $reversesId = null): BalanceLedgerLine
    {
        $ownerId = UserIdResolver::getId($owner);
        if (!$this->accessGuard->canAdjustBalance($actor, $ownerId, $scopeKey)) {
            throw new LedgerRefusedException('forbidden');
        }
        if ($kind === LedgerKind::OvertimeRatified) {
            throw new LedgerRefusedException('ratify_only');
        }
        try {
            Period::date($effectiveDate);
        } catch (InvalidArgumentException) {
            throw new LedgerRefusedException('invalid_date');
        }
        $reason = $reason === null ? null : NoteSanitizer::clean($reason);
        if ($reason === '') {
            $reason = null;
        }
        if ($kind->requiresReason() && $reason === null) {
            throw new LedgerRefusedException('reason_required');
        }
        $reason = $reason === null ? null : mb_substr($reason, 0, self::REASON_MAX_LENGTH);

        $reversed = null;
        if ($kind === LedgerKind::Reversal) {
            $reversed = $reversesId === null ? null : $this->lines->find($reversesId);
            if (!$reversed instanceof BalanceLedgerLine || $reversed->getOwnerRef() !== $ownerId || $reversed->getScopeKey() !== $scopeKey) {
                throw new LedgerRefusedException('not_found');
            }
            if ($reversed->getKind() === LedgerKind::Reversal) {
                throw new LedgerRefusedException('not_reversible');
            }
            if ($this->lines->findReversalOf($reversed->getId()) instanceof BalanceLedgerLine) {
                throw new LedgerRefusedException('already_reversed');
            }
            $seconds = -$reversed->getSeconds();
        } elseif (!$this->validSeconds($kind, $seconds)) {
            throw new LedgerRefusedException('invalid_seconds');
        }
        if ($kind === LedgerKind::Opening && $this->hasOpening($ownerId, $scopeKey)) {
            throw new LedgerRefusedException('opening_exists');
        }
        $locked = $this->lockGuard->lockingTimesheetForDate($ownerId, $scopeKey, $effectiveDate);
        if ($locked instanceof Timesheet) {
            throw new PeriodLockedException($locked->getId(), $locked->getPeriodEnd(), WriteKind::Ledger);
        }

        $line = $this->append($owner, $ownerId, $scopeKey, $kind, $seconds, $effectiveDate, $reason, null, $reversed?->getId(), UserIdResolver::getId($actor));
        try {
            $this->lines->flush();
        } catch (UniqueConstraintViolationException) {
            // Another line was appended to the chain at the same time (unique owner, scope and previous hash): the
            // chain does not fork, this line is not written.
            throw new LedgerRefusedException('conflict');
        }
        $this->dispatch($line);

        return $line;
    }

    /**
     * The approval's own `overtime_ratified` line (internal: the timesheet service, inside its transaction; the caller
     * flushes and then calls {@see self::dispatch()}). Allowed in the period being locked.
     *
     * @internal
     */
    public function ratify(?UserInterface $owner, string $ownerId, Timesheet $timesheet, int $seconds, string $actorRef): BalanceLedgerLine
    {
        return $this->append($owner, $ownerId, $timesheet->getScopeKey(), LedgerKind::OvertimeRatified, $seconds, $timesheet->getPeriodEnd(), null, $timesheet, null, $actorRef);
    }

    /**
     * Reverses the `overtime_ratified` lines of a timesheet that are not reversed yet (internal: reopen, inside its
     * transaction).
     *
     * @internal
     *
     * @return list<BalanceLedgerLine> the reversals
     */
    public function reverseRatified(?UserInterface $owner, Timesheet $timesheet, string $actorRef, string $reason): array
    {
        $reversals = [];
        $previous  = null;
        foreach ($this->lines->findByTimesheet($timesheet->getId()) as $line) {
            if ($line->getKind() !== LedgerKind::OvertimeRatified || $this->lines->findReversalOf($line->getId()) instanceof BalanceLedgerLine) {
                continue;
            }
            // Unflushed lines are not visible to findLast(): chain the next reversal to this one explicitly.
            $previous    = $this->append($owner, $line->getOwnerRef(), $line->getScopeKey(), LedgerKind::Reversal, -$line->getSeconds(), $line->getEffectiveDate(), mb_substr($reason, 0, self::REASON_MAX_LENGTH), $timesheet, $line->getId(), $actorRef, $previous);
            $reversals[] = $previous;
        }

        return $reversals;
    }

    /**
     * Fires {@see BalanceLedgerLineAddedEvent} for a flushed line.
     *
     * @internal
     */
    public function dispatch(BalanceLedgerLine $line): void
    {
        $this->eventDispatcher?->dispatch(new BalanceLedgerLineAddedEvent($line->getId(), $line->getOwnerRef(), $line->getScopeKey(), $line->getKind(), $line->getSeconds(), $line->getActorRef()), TimeTrackEvents::BALANCE_LEDGER_LINE_ADDED);
        $this->logger->info('TimeTrack balance ledger line added.', ['bundle' => 'time_track', 'action' => 'ledger_add', 'kind' => $line->getKind()->value]);
    }

    /**
     * Lines of an owner and scope in a date range (chain order).
     *
     * @return list<BalanceLedgerLine>
     */
    public function linesOf(string $ownerId, string $scopeKey, ?string $fromDate = null, ?string $toDate = null): array
    {
        return $this->lines->findByOwner($ownerId, $scopeKey, $fromDate, $toDate);
    }

    private function append(?UserInterface $owner, string $ownerId, string $scopeKey, LedgerKind $kind, int $seconds, string $effectiveDate, ?string $reason, ?Timesheet $timesheet, ?string $reversesId, string $actorRef, ?BalanceLedgerLine $after = null): BalanceLedgerLine
    {
        $previous = $after ?? $this->lines->findLast($ownerId, $scopeKey);
        $line     = new BalanceLedgerLine($owner, $ownerId, $scopeKey, $effectiveDate, $kind, $seconds, $this->clock->now(), $previous?->getHash(), $reason, $timesheet, $reversesId, $actorRef);
        // @igor-ignore - I/O delegate (repository call); no worker state.
        $this->lines->append($line);

        return $line;
    }

    private function validSeconds(LedgerKind $kind, int $seconds): bool
    {
        if (abs($seconds) > self::MAX_SECONDS) {
            return false;
        }

        return match ($kind) {
            LedgerKind::OvertimePaid        => $seconds < 0,
            LedgerKind::OvertimeCompensated => $seconds > 0,
            LedgerKind::Adjustment          => $seconds !== 0,
            default                         => true,
        };
    }

    private function hasOpening(string $ownerId, string $scopeKey): bool
    {
        foreach ($this->lines->findByOwner($ownerId, $scopeKey) as $line) {
            if ($line->getKind() === LedgerKind::Opening && !$this->lines->findReversalOf($line->getId()) instanceof BalanceLedgerLine) {
                return true;
            }
        }

        return false;
    }
}
