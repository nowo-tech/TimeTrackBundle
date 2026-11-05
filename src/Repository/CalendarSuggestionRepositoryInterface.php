<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Enum\CalendarSuggestionStatus;

/**
 * Since 1.14: store of the calendar suggestions (contract §3.6).
 */
interface CalendarSuggestionRepositoryInterface
{
    /**
     * The suggestion of a connection and event reference.
     */
    public function findSuggestionByRef(string $connectionId, string $eventRef): ?CalendarSuggestion;

    /**
     * Schedules a new suggestion for the next flush.
     */
    public function addSuggestion(CalendarSuggestion $suggestion): void;

    /**
     * Writes the pending changes.
     */
    public function flushSuggestions(): void;

    /**
     * Suggestions of a user (one scope or all) in the statuses, starting within `[from, to)`, oldest first.
     *
     * @param list<CalendarSuggestionStatus> $statuses
     *
     * @return list<CalendarSuggestion>
     */
    public function findSuggestionsByUser(string $userId, ?string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to, array $statuses): array;

    /**
     * Suggestions of the user with these ids (others are left out), oldest first.
     *
     * @param list<string> $ids
     *
     * @return list<CalendarSuggestion>
     */
    public function findSuggestionsByIds(string $userId, array $ids): array;

    /**
     * Suggestions of the user in a group, oldest first.
     *
     * @return list<CalendarSuggestion>
     */
    public function findSuggestionsByGroup(string $userId, string $groupKey): array;

    /**
     * Deletes the suggestions whose `expires_at` is before `$now` (of one connection, or all); returns the count.
     */
    public function deleteExpiredSuggestions(DateTimeImmutable $now, ?string $connectionId = null): int;
}
