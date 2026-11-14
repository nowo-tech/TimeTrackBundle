<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.26.0 (Beacon Q3, `nowo_time_track.monitoring.span_discarded`): the worker gave up a span of their own
 * tracked time — idle `discard` (`[from, now)`: the timer stopped at `from`), idle `split` (`[from, to)`), or a backdated
 * stop (`[applied end, now)`). Dispatched after the entry was recorded, only while activity monitoring is configured.
 * Listen asynchronously (dispatch a Messenger message with retries): the bundle catches and logs a listener that
 * throws, so a failed deletion must be retried on your side, never dropped. The host's evidence store deletes the screenshots, discard markers and activity of
 * the worker inside `[from, to)` (by user and time, whatever the entry id). Framework-free; carries no evidence.
 */
final readonly class MonitoringSpanDiscardedEvent
{
    /** Idle time discarded and the timer stopped. */
    public const REASON_IDLE_DISCARD = 'idle_discard';

    /** Idle time discarded and the timer continued. */
    public const REASON_IDLE_SPLIT = 'idle_split';

    /** Since audit PM delta D1-M1: the timer was stopped at a client-supplied end before now (`timer/stop` `endedAt`). */
    public const REASON_BACKDATED_STOP = 'backdated_stop';

    /**
     * @param string $reason {@see self::REASON_IDLE_DISCARD}, {@see self::REASON_IDLE_SPLIT} or {@see self::REASON_BACKDATED_STOP}
     */
    public function __construct(
        public TimeTrackUserRef $user,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $reason,
    ) {
    }
}
