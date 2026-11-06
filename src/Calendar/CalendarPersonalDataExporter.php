<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Enum\CalendarSuggestionStatus;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarRuleRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarSuggestionRepositoryInterface;

use function array_map;

/**
 * Since 1.14 (contract §12.4): the calendar data the bundle keeps about a person, for the host's GDPR art. 15 / 20
 * export — connections (no credential reference), rules and suggestions (task, times, status; there is no event data to
 * export). All of it is deleted with the account (`ON DELETE CASCADE`) and on revoke.
 */
final readonly class CalendarPersonalDataExporter
{
    /**
     * Receives the stores.
     */
    public function __construct(
        private CalendarConnectionRepositoryInterface $connections,
        private CalendarRuleRepositoryInterface $rules,
        private CalendarSuggestionRepositoryInterface $suggestions,
    ) {
    }

    /**
     * The person's calendar connections, rules and suggestions.
     *
     * @return array{calendarConnections: list<array<string, mixed>>, calendarRules: list<array<string, mixed>>, calendarSuggestions: list<array<string, mixed>>}
     */
    public function export(string $userId): array
    {
        return [
            'calendarConnections' => array_map(static fn (CalendarConnection $c): array => $c->toArray(), $this->connections->findConnectionsByUser($userId)),
            'calendarRules'       => array_map(static fn (CalendarRule $r): array => $r->toArray(), $this->rules->findRulesByOwner($userId)),
            'calendarSuggestions' => array_map(static fn (CalendarSuggestion $s): array => [
                'taskId'    => $s->getTaskId(),
                'status'    => $s->getStatus()->value,
                'matchKind' => $s->getMatchKind()->value,
                'groupKey'  => $s->getGroupKey(),
                'entryId'   => $s->getEntry()?->getId(),
            ] + $s->toOccurrence(), $this->suggestions->findSuggestionsByUser($userId, null, new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2100-01-01'), CalendarSuggestionStatus::cases())),
        ];
    }
}
