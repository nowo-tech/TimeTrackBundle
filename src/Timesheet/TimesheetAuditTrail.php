<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use DateTimeInterface;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function mb_substr;

/**
 * Since 1.13: appends the audit rows of a timesheet (contract §3.3): `seq` is the next number, `prev_hash` the
 * timesheet's head, the row's hash becomes the new head. Comments are cleaned like notes and cut to 1000 characters.
 * Also the `edit_blocked` recorder of `timesheets.audit_blocked_edits` (at most one row per timesheet per minute).
 */
final readonly class TimesheetAuditTrail implements BlockedEditRecorderInterface
{
    /** Longest stored comment, in code points. */
    public const COMMENT_MAX_LENGTH = 1000;

    /** Seconds between two `edit_blocked` rows of one timesheet. */
    public const BLOCKED_EDIT_INTERVAL = 60;

    private ClockInterface $clock;

    /**
     * Receives the stores.
     */
    public function __construct(
        private TimesheetEventRepositoryInterface $events,
        private TimesheetRepositoryInterface $timesheets,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Appends one row (inside the caller's transaction) and moves the timesheet head.
     *
     * @param array<string, mixed>|null $totals
     */
    public function append(Timesheet $timesheet, TimesheetAction $action, TimesheetStatus $from, TimesheetStatus $to, ?string $actorRef, TimesheetActorRole $role, AuditClient $client, DateTimeInterface $at, ?string $comment = null, ?string $entriesHash = null, ?array $totals = null): TimesheetEvent
    {
        $comment = $comment === null ? null : NoteSanitizer::clean($comment);
        $last    = $this->events->findLast($timesheet->getId());
        $event   = new TimesheetEvent(
            $timesheet,
            ($last?->getSeq() ?? 0) + 1,
            $action,
            $from,
            $to,
            $actorRef,
            $role,
            $client,
            $at,
            $timesheet->getHeadHash(),
            $comment === null || $comment === '' ? null : mb_substr($comment, 0, self::COMMENT_MAX_LENGTH),
            $entriesHash,
            $totals,
        );
        // @igor-ignore - I/O delegate (repository call); no worker state.
        $this->events->append($event);
        $timesheet->moveHead($event->getHash(), $at);

        return $event;
    }

    /**
     * Records a refused write as an `edit_blocked` row (rate limited), in its own transaction.
     *
     * Since 1.31.0 (audit QO-L4) the timesheet's row is locked and read again first, then the last row and the head are
     * read under that lock, exactly as a transition does: a transition of the same timesheet that commits at that moment
     * is waited for instead of failing the append on the version or sequence key (which closed the shared entity
     * manager and answered 409 / 500 instead of the `period_locked` refusal). The caller already holds the owner's lock
     * (the documented order: owner, then timesheet), so the two never wait for each other in a cycle.
     */
    public function record(Timesheet $timesheet, string $userRef, WriteKind $kind): void
    {
        // Rate limit first, without a lock: a repeated refusal costs one read (checked again under the lock below).
        if ($this->recentlyBlocked($timesheet->getId(), $this->clock->now())) {
            return;
        }
        // @igor-ignore - the closure runs once inside this call (transaction body), it is not stored.
        $this->timesheets->transactional(function () use ($timesheet, $userRef, $kind): void {
            $locked = $this->timesheets instanceof DoctrineOrmTimesheetRepository ? $this->timesheets->lockForTransition($timesheet->getId()) : $timesheet;
            if (!$locked instanceof Timesheet) {
                return;
            }
            $now = $this->clock->now();
            if ($this->recentlyBlocked($locked->getId(), $now)) {
                return;
            }
            $role = $userRef === $locked->getOwnerRef() ? TimesheetActorRole::Owner : TimesheetActorRole::System;
            $this->append($locked, TimesheetAction::EditBlocked, $locked->getStatus(), $locked->getStatus(), $userRef, $role, AuditClient::System, $now, $kind->value);
        });
    }

    /**
     * Whether the timesheet's last row is an `edit_blocked` one younger than {@see self::BLOCKED_EDIT_INTERVAL}.
     */
    private function recentlyBlocked(string $timesheetId, DateTimeInterface $now): bool
    {
        $last = $this->events->findLast($timesheetId);

        return $last instanceof TimesheetEvent && $last->getAction() === TimesheetAction::EditBlocked && $now->getTimestamp() - $last->getOccurredAt()->getTimestamp() < self::BLOCKED_EDIT_INTERVAL;
    }
}
