<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see WorkedTimeSourceInterface} (REQ-ARCH-001 rule 3), same
 * `working_time.worked_time_source` option. The bundle reads it, never writes it.
 */
interface WorkedTimeRecordInterface
{
    /**
     * Whether this source is the host's legal working-time register (true) or task time (false).
     */
    public function isLegalRecord(): bool;

    /**
     * Worked days of [$from, $to] (local dates, inclusive) keyed by `Y-m-d`; dates without work may be omitted.
     *
     * @return array<string, WorkedDay>
     */
    public function workedDaysOf(TimeTrackUserRef $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
