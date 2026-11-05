<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use LogicException;

/**
 * Since 1.14: one calendar event occurrence read by an adapter (contract §6.2). It lives **only in memory** during one
 * sync: never stored, logged, dispatched or cached. Debug output is redacted and serialization throws; the title is
 * read once by the matcher and dropped.
 */
final readonly class CalendarEventView
{
    /**
     * @param string $eventId Provider event (occurrence) id
     * @param string|null $seriesId Provider recurring series id (null for a single event)
     * @param string $title Event title (`summary` / `subject`)
     * @param bool $free Marked free / transparent (Google `transparency`, Graph `showAs`)
     * @param bool $declined Declined by the user
     * @param bool $otherKind Google `eventType` other than `default` (out of office, focus time, working location, ...)
     */
    public function __construct(
        public string $eventId,
        public ?string $seriesId,
        public string $title,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public bool $allDay = false,
        public bool $cancelled = false,
        public bool $free = false,
        public bool $declined = false,
        public bool $otherKind = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['eventId' => '[redacted]', 'seriesId' => '[redacted]', 'title' => '[redacted]', 'start' => $this->start, 'end' => $this->end];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A calendar event view cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A calendar event view cannot be unserialized.');
    }
}
