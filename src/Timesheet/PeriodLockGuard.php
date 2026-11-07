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
use Nowo\TimeTrackBundle\Repository\FormerOwnerTimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\LockingGuardTimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function gmdate;
use function max;
use function min;

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
     * Since 1.16 (audit HM-L4): {@see self::isRowBlocked()} for a move, inside its transaction: with a store that can, the
     * timesheets are read with a shared lock, so on MySQL and MariaDB a period locked during the move is seen or waits for it,
     * instead of being hidden by the transaction's snapshot.
     */
    public function isMoveBlocked(?string $userId, ?string $personRef, ?string $scopeKey, DateTimeInterface $startedAt): bool
    {
        $ownerId = $userId ?? TimeEntry::formerUserIdOf($personRef);
        if ($ownerId === null || !$this->timesheets instanceof LockingGuardTimesheetRepositoryInterface) {
            return $this->isRowBlocked($userId, $personRef, $scopeKey, $startedAt);
        }
        $utc = new DateTimeImmutable('@' . $startedAt->getTimestamp());
        foreach ($this->timesheets->findGuardingForMove($ownerId, $scopeKey, $utc->modify('-1 day')->format('Y-m-d'), $utc->modify('+1 day')->format('Y-m-d')) as $timesheet) {
            if ($timesheet->covers($startedAt)) {
                return true;
            }
        }

        return false;
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
     * The hard-locked timesheet of the owner and scope whose period contains the local date (`Y-m-d`), or null: what
     * a balance ledger line with that effective date is checked against (contract §3.4).
     */
    public function lockingTimesheetForDate(string $ownerId, string $scopeKey, string $localDate): ?Timesheet
    {
        if (!$this->enabled) {
            return null;
        }
        foreach ($this->timesheets->findGuarding($ownerId, $scopeKey, $localDate, $localDate) as $timesheet) {
            if ($timesheet->isLocked()) {
                return $timesheet;
            }
        }

        return null;
    }

    /**
     * Whether an entry may be changed or deleted by any bundle path: false when its period (local start date, its
     * scope or, without one, any scope of its owner) is locked or submitted, and when its owner is unknown. Since 1.15
     * an ownerless entry is judged by the deleted user's timesheets, which stay under the same id; a former person's
     * entry has no timesheet, and an ownerless entry with no reference (the delete bypassed the bundle, or it was
     * anonymised) has an unknown owner.
     */
    public function isEntryWritable(TimeEntry $entry): bool
    {
        if (!$this->enabled) {
            return true;
        }
        $owner = $entry->getUser();
        if ($owner === null) {
            return !$this->isRowBlocked(null, $entry->getPersonRef(), $entry->getScopeKey(), $entry->getStartedAt());
        }
        if (!$owner instanceof UserInterface) {
            return false;
        }

        return !$this->blockingTimesheet($owner, $entry->getScopeKey(), $entry->getStartedAt()) instanceof Timesheet;
    }

    /**
     * Since 1.15: the opposite of {@see self::isEntryWritable()} by ids, for bulk paths that only read ids. It does not
     * check {@see self::isEnabled()}: callers ask only while the guard is on.
     */
    public function isRowBlocked(?string $userId, ?string $personRef, ?string $scopeKey, DateTimeInterface $startedAt): bool
    {
        if ($userId !== null) {
            return $this->blockingTimesheetOf($userId, $scopeKey, $startedAt) instanceof Timesheet;
        }
        $formerUserId = TimeEntry::formerUserIdOf($personRef);
        if ($formerUserId === null) {
            return $personRef === null;
        }
        // The account may be gone, and its timesheets with no `user_id`: they are found by `owner_ref`. A store that cannot
        // do that fails closed.
        if (!$this->timesheets instanceof FormerOwnerTimesheetRepositoryInterface) {
            return true;
        }
        $utc = new DateTimeImmutable('@' . $startedAt->getTimestamp());
        foreach ($this->timesheets->findGuardingByOwnerRef($formerUserId, $scopeKey, $utc->modify('-1 day')->format('Y-m-d'), $utc->modify('+1 day')->format('Y-m-d')) as $timesheet) {
            if ($timesheet->covers($startedAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ids of the entries no bundle path may change (their period is locked or submitted, or their owner is unknown):
     * one query per owner for the whole list (client API `entries` / `export`: `locked`).
     *
     * @param list<TimeEntry> $entries
     *
     * @return array<string, true>
     */
    public function blockedEntryIds(array $entries): array
    {
        if (!$this->enabled || $entries === []) {
            return [];
        }
        $byOwner = [];
        $blocked = [];
        foreach ($entries as $entry) {
            $owner = $entry->getUser();
            if ($owner === null) {
                if (!$this->isEntryWritable($entry)) {
                    $blocked[$entry->getId()] = true;
                }
                continue;
            }
            if (!$owner instanceof UserInterface) {
                $blocked[$entry->getId()] = true;
                continue;
            }
            // Keyed by a prefix: numeric ids would become integer keys.
            $ownerId                           = UserIdResolver::getId($owner);
            $byOwner['o' . $ownerId]['id']     = $ownerId;
            $byOwner['o' . $ownerId]['list'][] = $entry;
        }
        foreach ($byOwner as ['id' => $ownerId, 'list' => $list]) {
            $min = null;
            $max = null;
            foreach ($list as $entry) {
                $ts  = $entry->getStartedAt()->getTimestamp();
                $min = $min === null ? $ts : min($min, $ts);
                $max = $max === null ? $ts : max($max, $ts);
            }
            $timesheets = $this->timesheets->findGuarding($ownerId, null, gmdate('Y-m-d', (int) $min - 86_400), gmdate('Y-m-d', (int) $max + 86_400));
            foreach ($list as $entry) {
                foreach ($timesheets as $timesheet) {
                    if (($entry->getScopeKey() === null || $entry->getScopeKey() === $timesheet->getScopeKey()) && $timesheet->covers($entry->getStartedAt())) {
                        $blocked[$entry->getId()] = true;
                        break;
                    }
                }
            }
        }

        return $blocked;
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
