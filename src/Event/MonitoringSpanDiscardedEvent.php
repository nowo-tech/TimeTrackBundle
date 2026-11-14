<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.26.0 (Beacon Q3, `nowo_time_track.monitoring.span_discarded`): the worker gave up a span of their own
 * tracked time — idle `discard` (`[from, now)`: the timer stopped at `from`), idle `split` (`[from, to)`), idle
 * `reassign` + `then: stop` (`[to, now)`), or a stop at an end before now (`[end, now)`: a backdated `timer/stop` or host
 * code `TimerService::stop($user, $past)`). Spans may overlap (an idle discard is also a stop at `from`): delete
 * idempotently. Dispatched after the entry was recorded, only while activity monitoring is configured.
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

    /** Since audit PM delta D1-M1: the timer was stopped at an end before now (`timer/stop` `endedAt`, or host code). */
    public const REASON_BACKDATED_STOP = 'backdated_stop';

    /** Since audit PM delta D2-L1: the idle span was reassigned to another task and the timer stopped: `[to, now)`. */
    public const REASON_IDLE_REASSIGN_STOP = 'idle_reassign_stop';

    /**
     * @param string $reason {@see self::REASON_IDLE_DISCARD}, {@see self::REASON_IDLE_SPLIT}, {@see self::REASON_IDLE_REASSIGN_STOP} or {@see self::REASON_BACKDATED_STOP}
     */
    public function __construct(
        public TimeTrackUserRef $user,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $reason,
    ) {
    }
}
