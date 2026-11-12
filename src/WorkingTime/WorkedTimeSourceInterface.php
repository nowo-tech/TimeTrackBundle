<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `working_time.worked_time_source`): worked time per local date — the host's working-time
 * register (art. 34.9 ET, Beacon spec 198) or, by default, live task time ({@see TaskTimeWorkedTimeSource},
 * `legalRecord: false`). The bundle reads it, never writes it.
 *
 * @deprecated since 1.23.0, implement {@see WorkedTimeRecordInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface WorkedTimeSourceInterface
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
    public function workedDays(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
