<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

use function max;
use function min;

/**
 * Limits of client-supplied start / end times (client API 1.10, `client_api.backdate.*`).
 */
final readonly class BackdateLimits
{
    /** Upper bound of `max_seconds` (24 h; hosts may only lower it). */
    public const MAX_SECONDS = 86_400;

    /** Upper bound of `clock_skew_seconds`. */
    public const MAX_CLOCK_SKEW_SECONDS = 900;

    /** How far back a start may go, in seconds (0 = backdating off). */
    public int $maxSeconds;

    /** Future tolerance read as "now", in seconds. */
    public int $clockSkewSeconds;

    /**
     * Clamps both values into their ranges.
     */
    public function __construct(int $maxSeconds = self::MAX_SECONDS, int $clockSkewSeconds = 120)
    {
        $this->maxSeconds       = max(0, min(self::MAX_SECONDS, $maxSeconds));
        $this->clockSkewSeconds = max(0, min(self::MAX_CLOCK_SKEW_SECONDS, $clockSkewSeconds));
    }

    /**
     * Whether clients may send a start or end time.
     */
    public function isEnabled(): bool
    {
        return $this->maxSeconds > 0;
    }
}
