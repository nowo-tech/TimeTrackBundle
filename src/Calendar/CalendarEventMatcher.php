<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Enum\CalendarMatchKind;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;
use function count;
use function is_string;
use function mb_stripos;
use function mb_strlen;

/**
 * Since 1.14 (contract §6.3): turns calendar events into task matches, in memory.
 *
 * 1. Drops cancelled, all-day, free / transparent, declined and non-default-kind events, events longer than
 *    `max_event_seconds`, and events outside `[now − lookback_days, now]` (an event must have ended: future events are
 *    never suggested).
 * 2. Extracts task keys from the title (host {@see TaskKeyResolverInterface}, ≤ 5 keys of ≤ 32 characters); without a
 *    resolvable key, the user's rules are tried (literal, case-insensitive, first match wins).
 * 3. Resolves every key in one host call and keeps tasks the user can track; two tasks for one event → the first key in
 *    title order.
 * 4. Anything without a match is discarded and only counted (`ignored`). The title is read in step 2 and dropped.
 */
final readonly class CalendarEventMatcher
{
    /** Keys read per title. */
    public const MAX_KEYS_PER_TITLE = 5;

    /** Longest key. */
    public const MAX_KEY_LENGTH = 32;

    /**
     * Receives the host resolver, the track check, the reference hasher and the settings.
     */
    public function __construct(
        private TaskKeyResolverInterface $resolver,
        private TaskAccessCheckerInterface $taskAccess,
        private CalendarRefHasher $hasher,
        private CalendarSettings $settings,
    ) {
    }

    /**
     * Matches of the events for the connection owner.
     *
     * @param iterable<CalendarEventView> $events
     * @param list<CalendarRule> $rules The owner's rules in the connection scope, oldest first
     *
     * @return array{matches: list<CalendarMatch>, fetched: int, ignored: int}
     */
    public function match(UserInterface $user, CalendarConnection $connection, iterable $events, array $rules, DateTimeImmutable $now): array
    {
        $userId   = UserIdResolver::getId($user);
        $provider = $connection->getProvider();
        $oldest   = $now->getTimestamp() - $this->settings->lookbackDays * 86_400;
        $fetched  = 0;
        $ignored  = 0;
        /** @var list<array{event: string, series: string|null, start: DateTimeImmutable, end: DateTimeImmutable, keys: list<string>, rule: CalendarRule|null}> $candidates */
        $candidates = [];
        $allKeys    = [];
        foreach ($events as $event) {
            ++$fetched;
            $start = $event->start->getTimestamp();
            $end   = $event->end->getTimestamp();
            if ($event->cancelled || $event->allDay || $event->free || $event->declined || $event->otherKind
                || $end <= $start || $end - $start > $this->settings->maxEventSeconds || $start < $oldest || $end > $now->getTimestamp()) {
                ++$ignored;
                continue;
            }
            $keys = $this->keys($event->title);
            $rule = $this->rule($event->title, $rules);
            if ($keys === [] && !$rule instanceof CalendarRule) {
                ++$ignored;
                continue;
            }
            foreach ($keys as $key) {
                $allKeys[$key] = true;
            }
            $candidates[] = [
                'event'  => $this->hasher->eventRef($userId, $provider, $event->eventId, $event->start),
                'series' => $event->seriesId !== null ? $this->hasher->seriesRef($userId, $provider, $event->seriesId) : null,
                'start'  => $event->start,
                'end'    => $event->end,
                'keys'   => $keys,
                'rule'   => $rule,
            ];
        }

        $tasks     = $allKeys === [] ? [] : $this->resolver->resolve($user, $connection->getScopeKey(), array_keys($allKeys));
        $trackable = [];
        $matches   = [];
        foreach ($candidates as $candidate) {
            $match = $this->byKey($user, $candidate, $tasks, $trackable) ?? $this->byRule($user, $candidate, $trackable);
            if ($match instanceof CalendarMatch) {
                $matches[] = $match;
            } else {
                ++$ignored;
            }
        }

        return ['matches' => $matches, 'fetched' => $fetched, 'ignored' => $ignored];
    }

    /**
     * Keys of a title: at most 5, each a non-empty string of at most 32 characters, duplicates removed, title order.
     *
     * @return list<string>
     */
    public function keys(string $title): array
    {
        $keys = [];
        foreach ($this->resolver->extractKeys($title) as $key) {
            if (is_string($key) && $key !== '' && mb_strlen($key) <= self::MAX_KEY_LENGTH) {
                $keys[$key] = true;
            }
            if (count($keys) === self::MAX_KEYS_PER_TITLE) {
                break;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param list<CalendarRule> $rules
     */
    private function rule(string $title, array $rules): ?CalendarRule
    {
        foreach ($rules as $rule) {
            if (mb_stripos($title, $rule->getPattern()) !== false) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array{event: string, series: string|null, start: DateTimeImmutable, end: DateTimeImmutable, keys: list<string>, rule: CalendarRule|null} $candidate
     * @param array<string, TaskReference> $tasks
     * @param array<string, bool> $trackable
     */
    private function byKey(UserInterface $user, array $candidate, array $tasks, array &$trackable): ?CalendarMatch
    {
        foreach ($candidate['keys'] as $key) {
            $task = $tasks[$key] ?? null;
            if ($task instanceof TaskReference && $this->canTrack($user, $task->id, $trackable)) {
                return new CalendarMatch($candidate['event'], $candidate['series'], $task->id, CalendarMatchKind::Key, null, $candidate['series'] !== null, $candidate['start'], $candidate['end']);
            }
        }

        return null;
    }

    /**
     * @param array{event: string, series: string|null, start: DateTimeImmutable, end: DateTimeImmutable, keys: list<string>, rule: CalendarRule|null} $candidate
     * @param array<string, bool> $trackable
     */
    private function byRule(UserInterface $user, array $candidate, array &$trackable): ?CalendarMatch
    {
        $rule = $candidate['rule'];
        if (!$rule instanceof CalendarRule || !$this->canTrack($user, $rule->getTaskId(), $trackable)) {
            return null;
        }

        return new CalendarMatch($candidate['event'], $candidate['series'], $rule->getTaskId(), CalendarMatchKind::Rule, $rule->getId(), $rule->groupsMatches(), $candidate['start'], $candidate['end']);
    }

    /**
     * @param array<string, bool> $trackable
     */
    private function canTrack(UserInterface $user, string $taskId, array &$trackable): bool
    {
        return $trackable[$taskId] ??= $this->taskAccess->canTrack($user, $taskId);
    }
}
