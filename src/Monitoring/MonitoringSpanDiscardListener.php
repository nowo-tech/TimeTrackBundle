<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\MonitoringSpanDiscardedEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Since 1.26.0 (Beacon Q3, audit PM delta D1-M1): turns every span of tracked time a worker gives up into a
 * {@see MonitoringSpanDiscardedEvent}, so the host deletes its evidence:
 *
 * - idle `discard` (`timer/discard-idle`, `TimerService::resolveIdle()`): the timer stops at `from`, so `[from, now)`;
 * - idle `split`: the idle span `[from, to)` (the timer continues at `to`);
 * - a backdated stop (`timer/stop` with `endedAt`, `TimerService::stopAt()`): `[applied end, now)`.
 *
 * `keep` and `reassign` keep the time. Registered only while monitoring is configured. A host listener that throws is
 * caught and logged (class only): the discard is already committed and must not become a 500; hand the deletion to
 * an async message with retries. Stateless (FrankenPHP worker mode).
 */
final readonly class MonitoringSpanDiscardListener
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * Receives the dispatcher of the new event, the clock of "now" and the logger.
     */
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * `nowo_time_track.idle.resolved`: the discarded idle span.
     */
    public function onIdleResolved(IdleResolvedEvent $event): void
    {
        match ($event->getResolution()) {
            IdleResolution::Discard => $this->dispatch($event->userRef(), $event->getFrom(), $this->clock->now(), MonitoringSpanDiscardedEvent::REASON_IDLE_DISCARD),
            IdleResolution::Split   => $this->dispatch($event->userRef(), $event->getFrom(), $event->getTo(), MonitoringSpanDiscardedEvent::REASON_IDLE_SPLIT),
            default                 => null,
        };
    }

    /**
     * `nowo_time_track.timer.stop`: a stop at a client-supplied end before now gives up `[end, now)`.
     */
    public function onTimerStop(TimerStopEvent $event): void
    {
        $end = $event->getEndTime();
        $now = $this->clock->now();
        if ($end instanceof ClampedTime && $end->applied->getTimestamp() < $now->getTimestamp()) {
            $this->dispatch($event->userRef(), $end->applied, $now, MonitoringSpanDiscardedEvent::REASON_BACKDATED_STOP);
        }
    }

    private function dispatch(TimeTrackUserRef $user, DateTimeImmutable $from, DateTimeImmutable $to, string $reason): void
    {
        try {
            $this->eventDispatcher->dispatch(new MonitoringSpanDiscardedEvent($user, $from, $to, $reason), TimeTrackEvents::MONITORING_SPAN_DISCARDED);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack monitoring span listener failed.', ['bundle' => 'time_track', 'action' => 'monitoring_span_discarded', 'reason' => $reason, 'exception' => $exception::class]);
        }
    }
}
