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
     */
    public function record(Timesheet $timesheet, string $userRef, WriteKind $kind): void
    {
        $now  = $this->clock->now();
        $last = $this->events->findLast($timesheet->getId());
        if ($last instanceof TimesheetEvent && $last->getAction() === TimesheetAction::EditBlocked && $now->getTimestamp() - $last->getOccurredAt()->getTimestamp() < self::BLOCKED_EDIT_INTERVAL) {
            return;
        }
        $role = $userRef === $timesheet->getOwnerRef() ? TimesheetActorRole::Owner : TimesheetActorRole::System;
        $this->timesheets->transactional(fn (): TimesheetEvent => $this->append($timesheet, TimesheetAction::EditBlocked, $timesheet->getStatus(), $timesheet->getStatus(), $userRef, $role, AuditClient::System, $now, $kind->value));
    }
}
