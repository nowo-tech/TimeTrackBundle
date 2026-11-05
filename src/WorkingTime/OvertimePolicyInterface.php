<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

/**
 * Since 1.13 (host, `working_time.overtime_policy`): the overtime rules of a collective agreement (*convenio*).
 * Default: {@see ConfigOvertimePolicy}. The bundle only flags and totals overtime (art. 35.5 ET); it never pays,
 * converts or blocks work. `id` and `version` are stored in approved snapshots so a later rule change never rewrites
 * past periods.
 */
interface OvertimePolicyInterface
{
    /**
     * Policy id, e.g. "config", "convenio-metal-madrid-2025".
     */
    public function id(): string;

    /**
     * Bumped when the rules change.
     */
    public function version(): string;

    /**
     * Daily, weekly and year-to-date overtime of the input.
     */
    public function evaluate(OvertimeInput $input): OvertimeResult;
}
