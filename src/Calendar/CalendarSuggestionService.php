<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Enum\CalendarSuggestionStatus;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\CalendarSuggestionDecidedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Repository\CalendarSuggestionRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_sum;
use function array_values;
use function mb_strlen;

/**
 * Since 1.14 (contract §6.4–§6.6, §10.3): the owner's side of the calendar suggestions — list them grouped (dailies
 * per series or rule and ISO week), accept them into `calendar` entries one per occurrence (period lock, overlap and
 * track checks per occurrence; partial failures are reported per occurrence), dismiss them, and resolve task keys for
 * the desktop's local mode. Owner only: another user's suggestion ids behave like unknown ids.
 */
final readonly class CalendarSuggestionService
{
    /** Ids per accept / dismiss request. */
    public const MAX_IDS = 100;

    /** Keys per `resolve-keys` request. */
    public const MAX_KEYS = 50;

    /**
     * Receives the store, the entry writer, the track check, the settings and (local mode) the host resolver.
     */
    public function __construct(
        private CalendarSuggestionRepositoryInterface $suggestions,
        private ManualEntryService $entries,
        private TaskAccessCheckerInterface $taskAccess,
        private CalendarSettings $settings,
        private ?TaskKeyResolverInterface $resolver = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * The user's pending suggestions starting in `[from, to)`: groups (one item per `group_key`) and singles, each
     * `{groupKey, taskId, occurrences: [{id, startsAt, endsAt, seconds}], totalSeconds}`. No event data exists to show.
     *
     * @return array{groups: list<array<string, mixed>>, singles: list<array<string, mixed>>}
     */
    public function pending(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $groups  = [];
        $singles = [];
        foreach ($this->suggestions->findSuggestionsByUser(UserIdResolver::getId($user), $scopeKey, $from, $to, [CalendarSuggestionStatus::Pending]) as $suggestion) {
            $key = $suggestion->getGroupKey();
            if ($key === null) {
                $singles[] = $this->item(null, $suggestion->getTaskId(), [$suggestion]);
                continue;
            }
            $groups[$key] ??= ['taskId' => $suggestion->getTaskId(), 'members' => []];
            $groups[$key]['members'][] = $suggestion;
        }

        return [
            'groups'  => array_values(array_map(fn (string $key, array $group): array => $this->item($key, $group['taskId'], $group['members']), array_keys($groups), $groups)),
            'singles' => $singles,
        ];
    }

    /**
     * Accepts suggestions (by ids, or every pending one of a group) into `calendar` entries, one per occurrence.
     *
     * @param list<string>|null $ids Suggestion ids (null with a group key)
     * @param string|null $taskId Another task than the suggested one (must be trackable)
     *
     * @return list<array{id: string, entryId?: string, error?: string}> One outcome per requested occurrence
     */
    public function accept(UserInterface $user, ?array $ids, ?string $groupKey, ?string $taskId = null, ?string $note = null): array
    {
        $outcomes = [];
        $accepted = [];
        $entryIds = [];
        $userId   = UserIdResolver::getId($user);
        if ($taskId !== null && !$this->taskAccess->canTrack($user, $taskId)) {
            return array_map(static fn (string $id): array => ['id' => $id, 'error' => ManualEntryException::NOT_TRACKABLE], $this->requestedIds($user, $ids, $groupKey));
        }
        foreach ($this->resolve($user, $ids, $groupKey) as $id => $suggestion) {
            if (!$suggestion instanceof CalendarSuggestion || $suggestion->getStatus() !== CalendarSuggestionStatus::Pending) {
                $outcomes[] = ['id' => $id, 'error' => 'not_found'];
                continue;
            }
            try {
                $entry = $this->entries->create(
                    $user,
                    $taskId ?? $suggestion->getTaskId(),
                    $suggestion->getStartsAt(),
                    $suggestion->getEndsAt(),
                    TimeEntrySource::Calendar,
                    $note,
                    ['origin' => 'calendar', 'provider' => $suggestion->getConnection()->getProvider()->value],
                    $this->settings->lookbackDays,
                );
            } catch (ManualEntryException|PeriodWriteRefusedException $exception) {
                $outcomes[] = ['id' => $id, 'error' => $exception instanceof ManualEntryException ? $exception->errorCode : $exception->errorCode()];
                continue;
            }
            $suggestion->accept($entry);
            $outcomes[] = ['id' => $id, 'entryId' => $entry->getId()];
            $accepted[] = $id;
            $entryIds[] = $entry->getId();
        }
        if ($accepted !== []) {
            $this->suggestions->flushSuggestions();
            $this->eventDispatcher?->dispatch(new CalendarSuggestionDecidedEvent($userId, $accepted, 'accepted', $entryIds), TimeTrackEvents::CALENDAR_SUGGESTION_DECIDED);
        }

        return $outcomes;
    }

    /**
     * Dismisses pending suggestions (by ids, or a group): remembered until expiry so they are not suggested again.
     *
     * @param list<string>|null $ids
     *
     * @return list<string> The dismissed ids
     */
    public function dismiss(UserInterface $user, ?array $ids, ?string $groupKey): array
    {
        $dismissed = [];
        foreach ($this->resolve($user, $ids, $groupKey) as $id => $suggestion) {
            if ($suggestion instanceof CalendarSuggestion && $suggestion->getStatus() === CalendarSuggestionStatus::Pending) {
                $suggestion->dismiss();
                $dismissed[] = $id;
            }
        }
        if ($dismissed !== []) {
            $this->suggestions->flushSuggestions();
            $this->eventDispatcher?->dispatch(new CalendarSuggestionDecidedEvent(UserIdResolver::getId($user), $dismissed, 'dismissed'), TimeTrackEvents::CALENDAR_SUGGESTION_DECIDED);
        }

        return $dismissed;
    }

    /**
     * Local mode (contract §6.6): the tasks some keys mean in the user's scope, `{key: {taskId, title, boardId,
     * trackable}}`; the title and board are given only for tasks the user can track. Unknown keys are left out.
     *
     * @param list<string> $keys At most 50, each at most 32 characters
     *
     * @return array<string, array{taskId: string, title: string|null, boardId: string|null, trackable: bool}>
     */
    public function resolveKeys(UserInterface $user, string $scopeKey, array $keys): array
    {
        $resolver = $this->resolver;
        $wanted   = [];
        foreach ($keys as $key) {
            if ($key !== '' && mb_strlen($key) <= CalendarEventMatcher::MAX_KEY_LENGTH) {
                $wanted[$key] = true;
            }
        }
        if (!$resolver instanceof TaskKeyResolverInterface || $wanted === []) {
            return [];
        }
        $matches = [];
        foreach ($resolver->resolve($user, $scopeKey, array_keys($wanted)) as $key => $task) {
            if (!array_key_exists($key, $wanted) || !$task instanceof TaskReference) {
                continue;
            }
            $trackable     = $this->taskAccess->canTrack($user, $task->id);
            $matches[$key] = ['taskId' => $task->id, 'title' => $trackable ? $task->title : null, 'boardId' => $trackable ? $task->boardId : null, 'trackable' => $trackable];
        }

        return $matches;
    }

    /**
     * @param list<CalendarSuggestion> $members
     *
     * @return array{groupKey: string|null, taskId: string, occurrences: list<array{id: string, startsAt: string, endsAt: string, seconds: int}>, totalSeconds: int}
     */
    private function item(?string $groupKey, string $taskId, array $members): array
    {
        $occurrences = array_map(static fn (CalendarSuggestion $s): array => $s->toOccurrence(), $members);

        return ['groupKey' => $groupKey, 'taskId' => $taskId, 'occurrences' => $occurrences, 'totalSeconds' => array_sum(array_column($occurrences, 'seconds'))];
    }

    /**
     * The requested suggestions keyed by id (null for ids that are unknown or another user's).
     *
     * @param list<string>|null $ids
     *
     * @return array<string, CalendarSuggestion|null>
     */
    private function resolve(UserInterface $user, ?array $ids, ?string $groupKey): array
    {
        $userId = UserIdResolver::getId($user);
        $found  = $ids !== null
            ? $this->suggestions->findSuggestionsByIds($userId, $ids)
            : ($groupKey !== null ? $this->suggestions->findSuggestionsByGroup($userId, $groupKey) : []);
        $byId = [];
        foreach ($found as $suggestion) {
            $byId[$suggestion->getId()] = $suggestion;
        }
        if ($ids === null) {
            return $byId;
        }
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = $byId[$id] ?? null;
        }

        return $result;
    }

    /**
     * @param list<string>|null $ids
     *
     * @return list<string>
     */
    private function requestedIds(UserInterface $user, ?array $ids, ?string $groupKey): array
    {
        return $ids ?? array_keys($this->resolve($user, null, $groupKey));
    }
}
