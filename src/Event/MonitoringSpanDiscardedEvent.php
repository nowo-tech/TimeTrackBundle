<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.26.0 (Beacon Q3, `nowo_time_track.monitoring_span_discarded`): the worker discarded a span of their own
 * tracked time (idle time discarded, or discarded and the timer continued: `timer/discard-idle` actions `discard` and
 * `split`, or `TimerService::resolveIdle()` from host code). Dispatched after the entry was recorded, only while
 * activity monitoring is configured. The host's evidence store deletes the screenshots, discard markers and activity of
 * the worker inside `[from, to)` (by user and time, whatever the entry id). Framework-free; carries no evidence.
 */
final readonly class MonitoringSpanDiscardedEvent
{
    /** Idle time discarded and the timer stopped. */
    public const REASON_IDLE_DISCARD = 'idle_discard';

    /** Idle time discarded and the timer continued. */
    public const REASON_IDLE_SPLIT = 'idle_split';

    /**
     * @param string $reason {@see self::REASON_IDLE_DISCARD} or {@see self::REASON_IDLE_SPLIT}
     */
    public function __construct(
        public TimeTrackUserRef $user,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $reason,
    ) {
    }
}
