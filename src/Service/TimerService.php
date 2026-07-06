<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

final readonly class TimerService
{
    public function __construct(
        private TaskProviderInterface $taskProvider,
        private ActiveTimerRepositoryInterface $activeTimerRepository,
        private TimeEntryRepositoryInterface $timeEntryRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getActive(UserInterface $user): ?ActiveTimer
    {
        return $this->activeTimerRepository->findByUserId(UserIdResolver::getId($user));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function start(UserInterface $user, string $taskId, ClientType $clientType, array $metadata = []): ActiveTimer
    {
        $existing = $this->getActive($user);
        if ($existing instanceof ActiveTimer) {
            throw ActiveTimerConflictException::alreadyRunning($existing->getTaskId());
        }

        if (!$this->taskProvider->canUserTrack($user, $taskId)) {
            throw new InvalidArgumentException(sprintf('User cannot track task %s.', $taskId));
        }

        $task = $this->taskProvider->findForUser($taskId, $user);
        if (!$task instanceof TaskReference) {
            throw new InvalidArgumentException(sprintf('Task %s not found.', $taskId));
        }

        $timer = new ActiveTimer(
            $user,
            $task->id,
            $task->title,
            $task->boardId,
            $clientType,
            $metadata,
        );

        $this->activeTimerRepository->save($timer);
        $this->eventDispatcher->dispatch(new TimerStartEvent($user, $task, $clientType), TimeTrackEvents::TIMER_START);

        return $timer;
    }

    public function stop(UserInterface $user): ?TimeEntry
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        $endedAt  = new DateTimeImmutable();
        $duration = max(0, $endedAt->getTimestamp() - $timer->getStartedAt()->getTimestamp());

        $entry = new TimeEntry(
            $user,
            $timer->getTaskId(),
            $timer->getTaskTitleSnapshot(),
            $timer->getBoardIdSnapshot(),
            $timer->getStartedAt(),
            $endedAt,
            $duration,
            $this->mapClientTypeToSource($timer->getClientType()),
            $timer->getMetadata(),
        );

        $this->timeEntryRepository->save($entry);
        $this->activeTimerRepository->remove($timer);
        $this->eventDispatcher->dispatch(new TimerStopEvent($user, $entry), TimeTrackEvents::TIMER_STOP);

        return $entry;
    }

    public function heartbeat(UserInterface $user, bool $isIdle = false): ?ActiveTimer
    {
        $timer = $this->getActive($user);
        if (!$timer instanceof ActiveTimer) {
            return null;
        }

        $timer->heartbeat($isIdle);
        $this->activeTimerRepository->save($timer);

        return $timer;
    }

    /**
     * @return list<TaskReference>
     */
    public function listTasks(UserInterface $user, TaskListQuery $query): array
    {
        return $this->taskProvider->listTrackableForUser($user, $query);
    }

    /**
     * @return list<TimeEntry>
     */
    public function listEntries(UserInterface $viewer, DateTimeImmutable $from, DateTimeImmutable $to, ?string $targetUserId = null): array
    {
        $event = new TimeEntryListQueryEvent($viewer, $from, $to);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_LIST_QUERY);

        $userIds = $event->getUserIds();
        if ($userIds === []) {
            $userIds = [$targetUserId ?? UserIdResolver::getId($viewer)];
        }

        $entries = [];
        foreach ($userIds as $userId) {
            foreach ($this->timeEntryRepository->findByUserAndPeriod($userId, $from, $to) as $entry) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (TimeEntry $a, TimeEntry $b): int => $b->getStartedAt() <=> $a->getStartedAt());

        return $entries;
    }

    private function mapClientTypeToSource(ClientType $clientType): TimeEntrySource
    {
        return match ($clientType) {
            ClientType::Extension => TimeEntrySource::Extension,
            ClientType::Desktop   => TimeEntrySource::Desktop,
            ClientType::Web       => TimeEntrySource::Web,
        };
    }
}
