<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timer;

use function array_key_last;

/**
 * Since 1.25.0: what {@see HostTimerApi::start()} did: the new running timer and the account's timer it stopped first,
 * if any (Beacon spec 234 FR-024: "stops the first one and says so").
 */
final readonly class HostTimerStart
{
    /**
     * The most recent stopped entry, or null when no timer was running.
     */
    public ?TimerEntrySnapshot $stopped;

    /**
     * @param TimerSnapshot $timer The new running timer
     * @param list<TimerEntrySnapshot> $stoppedEntries Entries of the timers this start stopped, oldest first: one at most,
     *                                                 unless a concurrent start got in between (then its timer too)
     */
    public function __construct(
        public TimerSnapshot $timer,
        public array $stoppedEntries = [],
    ) {
        $this->stopped = $stoppedEntries === [] ? null : $stoppedEntries[array_key_last($stoppedEntries)];
    }

    /**
     * Whether the start stopped a running timer of the account.
     */
    public function replacedARunningTimer(): bool
    {
        return $this->stoppedEntries !== [];
    }
}
