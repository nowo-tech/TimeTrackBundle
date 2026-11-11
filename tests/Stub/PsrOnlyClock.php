<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Since 1.19.1: a host's own PSR-20 clock that is **not** a `Symfony\Component\Clock\ClockInterface` (like Cookie
 * Consent's `SystemClock`, which Beacon aliases to `Psr\Clock\ClockInterface`). Fixed time; no `sleep()`.
 */
final readonly class PsrOnlyClock implements ClockInterface
{
    public function __construct(private string $now = '2026-11-11 09:00:00')
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
    }
}
