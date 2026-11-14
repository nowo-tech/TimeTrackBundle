<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\MonitoringSpanDiscardedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Since 1.26.0 (Beacon Q3): turns every discarded span of tracked time into a {@see MonitoringSpanDiscardedEvent} so the
 * host deletes its evidence. Today the only way the bundle lets a worker discard time is the idle resolution
 * (`timer/discard-idle` or `TimerService::resolveIdle()`) with `discard` (timer stopped at the idle start) or `split`
 * (idle span dropped, timer continued); `keep` and `reassign` keep the time. Registered only while monitoring is
 * configured. Stateless (FrankenPHP worker mode).
 */
final readonly class MonitoringSpanDiscardListener
{
    /**
     * Receives the dispatcher of the new event.
     */
    public function __construct(private EventDispatcherInterface $eventDispatcher)
    {
    }

    /**
     * `nowo_time_track.idle_resolved`: re-dispatches a discarded span.
     */
    public function onIdleResolved(IdleResolvedEvent $event): void
    {
        $reason = match ($event->getResolution()) {
            IdleResolution::Discard => MonitoringSpanDiscardedEvent::REASON_IDLE_DISCARD,
            IdleResolution::Split   => MonitoringSpanDiscardedEvent::REASON_IDLE_SPLIT,
            default                 => null,
        };
        if ($reason !== null) {
            $this->eventDispatcher->dispatch(new MonitoringSpanDiscardedEvent($event->userRef(), $event->getFrom(), $event->getTo(), $reason), TimeTrackEvents::MONITORING_SPAN_DISCARDED);
        }
    }
}
