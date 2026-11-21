<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;

/**
 * Since 1.35.0 (DDD-host checklist B7): a running timer as {@see RunningTimerQueryInterface} returns it: the
 * {@see TimerSnapshot} that `HostTimerApi` returns, plus its owner and last heartbeat. Built per call and never kept.
 */
final readonly class RunningTimerView
{
    /**
     * @param TimerSnapshot $timer Task, start, client, channel and note of the timer
     * @param string $ownerId The owner's user id (as `TimeTrackUserRef::$id`)
     * @param DateTimeImmutable $lastHeartbeatAt The last heartbeat the client sent
     */
    public function __construct(
        public TimerSnapshot $timer,
        public string $ownerId,
        public DateTimeImmutable $lastHeartbeatAt,
    ) {
    }
}
