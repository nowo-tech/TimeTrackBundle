<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarSourceInterface;

/** Twin of {@see FixedWorkingCalendar} (same state). */
final class TwinWorkingCalendar implements WorkingCalendarSourceInterface
{
    public int $calls = 0;

    public function __construct(private readonly FixedWorkingCalendar $inner)
    {
    }

    public function daysFor(TimeTrackUserRef $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        ++$this->calls;

        return $this->inner->days(TwinPorts::user($user), $scopeKey, $from, $to);
    }
}
