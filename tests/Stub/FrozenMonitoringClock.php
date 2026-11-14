<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

use function time;

/**
 * Since 1.26.0 (audit PM-L8): the clock of the monitoring tests — read once when the kernel builds it, on the start of
 * the current 10-minute slot (always in the past), then frozen, so no test depends on a second or slot boundary.
 */
final readonly class FrozenMonitoringClock implements ClockInterface
{
    private int $at;

    public function __construct()
    {
        $now      = time();
        $this->at = $now - $now % 600;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->at);
    }
}
