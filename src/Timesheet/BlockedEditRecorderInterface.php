<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\WriteKind;

/**
 * Since 1.13: records a refused write as evidence (`timesheets.audit_blocked_edits`: one `edit_blocked` audit row per
 * timesheet per minute at most).
 */
interface BlockedEditRecorderInterface
{
    /**
     * Records the refusal of a write of `$userRef` in the timesheet's period.
     */
    public function record(Timesheet $timesheet, string $userRef, WriteKind $kind): void;
}
