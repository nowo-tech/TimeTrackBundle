<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\PeriodLockRefusedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Since 1.13: the period lock (contract §5.6). A period is **locked** for `(user, scope)` when a timesheet covering
 * the instant's local date has `locked_at` set; it is **submitted** (soft lock of the owner) while the timesheet waits
 * for a decision. Every bundle write path asks this guard before changing time, and no listener or role can override
 * it; the only way back is an audited reopen. A null scope (unassigned entry) is checked against **every** scope of the
 * user (fail-safe). Hosts that write entries themselves MUST call {@see self::assertWritable()} too.
 *
 * With timesheets off (`timesheets.enabled: false`) the guard allows everything without a query.
 */
final readonly class PeriodLockGuard
{
    /**
     * @param TimesheetRepositoryInterface $timesheets Timesheet store
     * @param bool $enabled `timesheets.enabled`
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches {@see PeriodLockRefusedEvent}
     * @param BlockedEditRecorderInterface|null $blockedEditRecorder Writes an `edit_blocked` audit row per refusal (`timesheets.audit_blocked_edits`)
     */
    public function __construct(
        private TimesheetRepositoryInterface $timesheets,
        private bool $enabled = true,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?BlockedEditRecorderInterface $blockedEditRecorder = null,
    ) {
    }

    /**
     * Whether the guard is active (timesheets on).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Refuses a write of the user's time at `$instant` in the scope (null = every scope of the user).
     *
     * @throws PeriodLockedException When the period is locked (409 `period_locked`)
     * @throws PeriodSubmittedException When the period is submitted (409 `period_submitted`)
     */
    public function assertWritable(UserInterface $user, ?string $scopeKey, DateTimeInterface $instant, WriteKind $kind): void
    {
        $refusal = $this->refusal($user, $scopeKey, $instant, $kind);
        if ($refusal instanceof PeriodWriteRefusedException) {
            throw $refusal;
        }
    }

    /**
     * The refusal a write would get (null = writable), after dispatching {@see PeriodLockRefusedEvent} for it.
     */
    public function refusal(UserInterface $user, ?string $scopeKey, DateTimeInterface $instant, WriteKind $kind): ?PeriodWriteRefusedException
    {
        $timesheet = $this->blockingTimesheet($user, $scopeKey, $instant);
        if (!$timesheet instanceof Timesheet) {
            return null;
        }

        $refusal = $timesheet->isLocked()
            ? new PeriodLockedException($timesheet->getId(), $timesheet->getPeriodEnd(), $kind)
            : new PeriodSubmittedException($timesheet->getId(), $timesheet->getPeriodEnd(), $kind);
        $userRef = UserIdResolver::getId($user);
        $this->eventDispatcher?->dispatch(
            new PeriodLockRefusedEvent($userRef, $timesheet->getScopeKey(), StorageTime::localDate($instant, $timesheet->getTimezone()), $kind, $timesheet->getId(), $refusal->errorCode()),
            TimeTrackEvents::PERIOD_LOCK_REFUSED,
        );
        $this->blockedEditRecorder?->record($timesheet, $userRef, $kind);

        return $refusal;
    }

    /**
     * The timesheet that blocks a write at `$instant` (locked first, then submitted), or null.
     */
    public function blockingTimesheet(UserInterface $user, ?string $scopeKey, DateTimeInterface $instant): ?Timesheet
    {
        return $this->blockingTimesheetOf(UserIdResolver::getId($user), $scopeKey, $instant);
    }

    /**
     * {@see self::blockingTimesheet()} by owner id (bulk paths that only read ids).
     */
    public function blockingTimesheetOf(string $ownerId, ?string $scopeKey, DateTimeInterface $instant): ?Timesheet
    {
        $candidates = $this->candidates($ownerId, $scopeKey, $instant);
        foreach ($candidates as $timesheet) {
            if ($timesheet->isLocked()) {
                return $timesheet;
            }
        }
        foreach ($candidates as $timesheet) {
            if ($timesheet->getStatus() === TimesheetStatus::Submitted) {
                return $timesheet;
            }
        }

        return null;
    }

    /**
     * The hard-locked timesheet covering `$instant` (ignores submitted periods), or null.
     */
    public function lockingTimesheet(UserInterface $user, ?string $scopeKey, DateTimeInterface $instant): ?Timesheet
    {
        foreach ($this->candidates(UserIdResolver::getId($user), $scopeKey, $instant) as $timesheet) {
            if ($timesheet->isLocked()) {
                return $timesheet;
            }
        }

        return null;
    }

    /**
     * Whether an entry may be changed or deleted by any bundle path: false when its period (local start date, its
     * scope or, without one, any scope of its owner) is locked or submitted, and when its owner is unknown.
     */
    public function isEntryWritable(TimeEntry $entry): bool
    {
        if (!$this->enabled) {
            return true;
        }
        $owner = $entry->getUser();
        if (!$owner instanceof UserInterface) {
            return false;
        }

        return !$this->blockingTimesheet($owner, $entry->getScopeKey(), $entry->getStartedAt()) instanceof Timesheet;
    }

    /**
     * @return list<Timesheet> the locked or submitted timesheets of the user whose period may contain the instant
     */
    private function candidates(string $ownerId, ?string $scopeKey, DateTimeInterface $instant): array
    {
        if (!$this->enabled) {
            return [];
        }

        // Any zone is at most 14 h from UTC: a day either side of the UTC date covers every local date of the instant.
        $utc        = new DateTimeImmutable('@' . $instant->getTimestamp());
        $timesheets = $this->timesheets->findGuarding(
            $ownerId,
            $scopeKey,
            $utc->modify('-1 day')->format('Y-m-d'),
            $utc->modify('+1 day')->format('Y-m-d'),
        );

        $covering = [];
        foreach ($timesheets as $timesheet) {
            if ($timesheet->covers($instant)) {
                $covering[] = $timesheet;
            }
        }

        return $covering;
    }
}
