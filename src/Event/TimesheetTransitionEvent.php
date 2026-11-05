<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.13: a timesheet transition was committed ({@see TimeTrackEvents::TIMESHEET_SUBMITTED}, `…_WITHDRAWN`,
 * `…_APPROVED`, `…_REJECTED`, `…_REOPENED`, `…_AUTO_LOCKED`). Ids only: the comment itself is never part of the event
 * (only whether there is one); hosts notify approvers and owners from it, inside the recipient's working hours.
 */
final class TimesheetTransitionEvent extends Event
{
    /**
     * @param array<string, mixed>|null $totals Snapshot (submit / approve), ids and numbers only
     */
    public function __construct(
        public readonly string $timesheetId,
        public readonly string $ownerRef,
        public readonly string $scopeKey,
        public readonly string $periodKey,
        public readonly TimesheetStatus $fromStatus,
        public readonly TimesheetStatus $toStatus,
        public readonly ?string $actorRef,
        public readonly TimesheetActorRole $actorRole,
        public readonly AuditClient $client,
        public readonly ?array $totals,
        public readonly bool $hasComment,
    ) {
    }
}
