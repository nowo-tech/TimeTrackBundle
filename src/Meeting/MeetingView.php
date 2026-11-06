<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use DateTimeImmutable;
use LogicException;

/**
 * Since 1.14 (contract §22.6): one meeting occurrence as the host provider returns it, already minimal. The bundle
 * filters it again before it leaves the API (private events cleared, link re-detected, attendees and task links
 * bounded). Debug output is redacted and serialization throws: it is never cached, queued or logged.
 */
final readonly class MeetingView
{
    /**
     * @param string $id Opaque occurrence id `mtg_…` (HMAC of provider ‖ calendar ‖ event id ‖ original start, Q-B20)
     * @param string|null $timeZone The event's IANA zone, if the provider gives one
     * @param string|null $joinUrl Raw candidate link; the bundle re-detects it
     * @param list<MeetingAttendeeView> $attendees Internal attendees only (Q-B18)
     * @param int|null $externalCount Attendees who are not host users (count only, Q-B17)
     * @param list<MeetingTaskLink> $taskLinks Linked tasks (Q-B21)
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public ?string $timeZone,
        public bool $allDay,
        public bool $busy,
        public bool $private,
        public ?string $title,
        public ?string $joinUrl,
        public bool $organizerIsMe,
        public array $attendees,
        public ?int $externalCount,
        public array $taskLinks,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'start' => $this->start, 'end' => $this->end, 'title' => '[redacted]', 'joinUrl' => '[redacted]', 'attendees' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A meeting view cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A meeting view cannot be unserialized.');
    }
}
