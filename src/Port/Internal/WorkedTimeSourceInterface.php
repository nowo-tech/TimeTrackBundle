<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\WorkingTime\TaskTimeWorkedTimeSource;
use Nowo\TimeTrackBundle\WorkingTime\WorkedDay;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeRecordInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `working_time.worked_time_source`): worked time per local date — the host's working-time
 * register (art. 34.9 ET, Beacon spec 198) or, by default, live task time ({@see TaskTimeWorkedTimeSource},
 * `legalRecord: false`). The bundle reads it, never writes it.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\WorkingTime\WorkedTimeSourceInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see WorkedTimeRecordInterface} (a gate adapts it).
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
