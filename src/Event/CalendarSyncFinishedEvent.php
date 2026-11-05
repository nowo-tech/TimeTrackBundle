<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.14 ({@see TimeTrackEvents::CALENDAR_SYNC_FINISHED}, contract §9.1): one connection was synced. Counts and the
 * error code only — never an event, a title or a reference.
 */
final class CalendarSyncFinishedEvent extends Event
{
    /**
     * Creates the event.
     *
     * @param array{fetched: int, matched: int, ignored: int, new: int, updated: int, expired: int} $counts
     * @param string|null $errorCode Null on success
     */
    public function __construct(
        public readonly string $connectionId,
        public readonly array $counts,
        public readonly ?string $errorCode,
    ) {
    }
}
