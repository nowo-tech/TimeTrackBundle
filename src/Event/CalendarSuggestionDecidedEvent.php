<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.14 ({@see TimeTrackEvents::CALENDAR_SUGGESTION_DECIDED}, contract §9.1): the owner accepted or dismissed
 * calendar suggestions. Ids only.
 */
final class CalendarSuggestionDecidedEvent extends Event
{
    /**
     * Creates the event.
     *
     * @param list<string> $suggestionIds Decided suggestions
     * @param string $decision `accepted` or `dismissed`
     * @param list<string> $entryIds Entries created (accepted only)
     */
    public function __construct(
        public readonly string $userRef,
        public readonly array $suggestionIds,
        public readonly string $decision,
        public readonly array $entryIds = [],
    ) {
    }
}
