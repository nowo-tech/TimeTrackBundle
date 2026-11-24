<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Workday;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.41.0 (owner decision D11 of the 2026-11-24 monitoring study; optional, framework-free, REQ-ARCH-001 rule 3):
 * the working day of a past local date in the host's working-time register (spec 198).
 *
 * Implement it on the service of `client_api.workday_provider` (next to {@see WorkdaySourceInterface} or the deprecated
 * {@see WorkdayProviderInterface}). Activity monitoring then refuses evidence taken during a break or outside the
 * clocked workday (`422 monitoring_refused {reason: on_break}`) for buffered items of earlier days too. Without it the
 * bundle checks only instants of the date {@see WorkdaySourceInterface::todayFor()} answers.
 *
 * Read only, side-effect free, nothing kept between calls (FrankenPHP worker mode, kernel reset false).
 */
interface WorkdayHistorySourceInterface
{
    /**
     * The user's working day on the local date (`Y-m-d`, in the register's time zone), with its `work` and `break`
     * segments. A day without clock-ins is an `idle` state with no segments, never null.
     *
     * Null means only that the register cannot give that day. Whether the register is on for the user comes from
     * `todayFor()` (null there = off, nothing is checked). While it is on, null for the date of the evidence refuses it
     * with `workday_unknown` (fail closed); null for the day before is read as "no night shift".
     */
    public function dayFor(TimeTrackUserRef $user, string $date): ?WorkdayState;
}
