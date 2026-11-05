<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Calendar\CalendarRefHasher;
use Nowo\TimeTrackBundle\Enum\CalendarMatchKind;
use Nowo\TimeTrackBundle\Enum\CalendarSuggestionStatus;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use const DATE_ATOM;

/**
 * Since 1.14: one calendar event occurrence that matched a task (contract §3.6). Holds **nothing** of the event but
 * its time range and two keyed, non-reversible references: `event_ref` (HMAC of provider ‖ event id ‖ occurrence
 * start, dedupes re-syncs and remembers dismissals) and `series_ref` (HMAC of the recurring series id, groups dailies).
 * No title, description, location, attendees, organiser, conference link, calendar name or provider id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_calendar_suggestions')]
#[ORM\UniqueConstraint(name: 'uniq_time_track_calendar_suggestions_ref', columns: ['connection_id', 'event_ref'])]
#[ORM\Index(name: 'idx_time_track_calendar_suggestions_user', columns: ['user_id', 'status', 'starts_at'])]
class CalendarSuggestion
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 12, enumType: CalendarSuggestionStatus::class)]
    private CalendarSuggestionStatus $status = CalendarSuggestionStatus::Pending;

    #[ORM\ManyToOne(targetEntity: TimeEntry::class)]
    #[ORM\JoinColumn(name: 'entry_id', nullable: true, onDelete: 'SET NULL')]
    private ?TimeEntry $entry = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    private DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable')]
    private DateTimeImmutable $endsAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    /**
     * A pending suggestion.
     *
     * @param string $eventRef 64 hex characters ({@see CalendarRefHasher})
     * @param string|null $seriesRef 64 hex characters, or null for a single event
     * @param string|null $groupKey `series:<16 hex>:<ISO week>` or `rule:<rule id hex>:<ISO week>`, or null
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: CalendarConnection::class)]
        #[ORM\JoinColumn(name: 'connection_id', nullable: false, onDelete: 'CASCADE')]
        private CalendarConnection $connection,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'event_ref', type: 'string', length: 64, options: ['fixed' => true])]
        private string $eventRef,
        #[ORM\Column(name: 'series_ref', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
        private ?string $seriesRef,
        #[ORM\Column(name: 'group_key', type: 'string', length: 48, nullable: true)]
        private ?string $groupKey,
        #[ORM\Column(name: 'task_id', type: 'string', length: 36)]
        private string $taskId,
        #[ORM\Column(name: 'match_kind', type: 'string', length: 8, enumType: CalendarMatchKind::class)]
        private CalendarMatchKind $matchKind,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ) {
        $this->id        = Uuid::generateV7()->toString();
        $this->startsAt  = StorageTime::normalize($startsAt);
        $this->endsAt    = StorageTime::normalize($endsAt);
        $this->expiresAt = StorageTime::normalize($expiresAt);
        $this->createdAt = StorageTime::normalize($now);
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The connection the event came from. */
    public function getConnection(): CalendarConnection
    {
        return $this->connection;
    }

    /** The owner. */
    public function getUser(): object
    {
        return $this->user;
    }

    /** Keyed reference of the occurrence. */
    public function getEventRef(): string
    {
        return $this->eventRef;
    }

    /** Keyed reference of the recurring series (null for a single event). */
    public function getSeriesRef(): ?string
    {
        return $this->seriesRef;
    }

    /** Group of dailies (null = single). */
    public function getGroupKey(): ?string
    {
        return $this->groupKey;
    }

    /** Resolved task id. */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /** Key or rule match. */
    public function getMatchKind(): CalendarMatchKind
    {
        return $this->matchKind;
    }

    /** Start of the occurrence. */
    public function getStartsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    /** End of the occurrence (clamped to `calendar.max_event_seconds`). */
    public function getEndsAt(): DateTimeImmutable
    {
        return $this->endsAt;
    }

    /** Current state. */
    public function getStatus(): CalendarSuggestionStatus
    {
        return $this->status;
    }

    /** Entry created on accept (null when none, or when the entry was deleted). */
    public function getEntry(): ?TimeEntry
    {
        return $this->entry;
    }

    /** When the row is purged. */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Creation time. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Length in seconds. */
    public function getDurationSeconds(): int
    {
        return $this->endsAt->getTimestamp() - $this->startsAt->getTimestamp();
    }

    /**
     * A re-sync of a pending suggestion: the event moved, was re-matched or changed group. Accepted and dismissed
     * suggestions keep their state and times (contract §6.3 step 5).
     *
     * @internal used by the calendar sync service
     *
     * @return bool Whether something changed
     */
    public function refresh(string $taskId, CalendarMatchKind $matchKind, ?string $groupKey, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, DateTimeImmutable $expiresAt): bool
    {
        if ($this->status !== CalendarSuggestionStatus::Pending) {
            return false;
        }
        $startsAt  = StorageTime::normalize($startsAt);
        $endsAt    = StorageTime::normalize($endsAt);
        $expiresAt = StorageTime::normalize($expiresAt);
        if ($this->taskId === $taskId && $this->matchKind === $matchKind && $this->groupKey === $groupKey
            && $this->startsAt == $startsAt && $this->endsAt == $endsAt) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskId = $taskId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->matchKind = $matchKind;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->groupKey = $groupKey;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->startsAt = $startsAt;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->endsAt = $endsAt;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->expiresAt = $expiresAt;

        return true;
    }

    /**
     * Accepted: keeps only the link to the entry until expiry.
     *
     * @internal used by the calendar suggestion service
     */
    public function accept(TimeEntry $entry): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->entry = $entry;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = CalendarSuggestionStatus::Accepted;
    }

    /**
     * Dismissed: remembered until expiry so the event is not suggested again.
     *
     * @internal used by the calendar suggestion service
     */
    public function dismiss(): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = CalendarSuggestionStatus::Dismissed;
    }

    /**
     * Client shape of one occurrence: id, times and duration (no event data exists to show).
     *
     * @return array{id: string, startsAt: string, endsAt: string, seconds: int}
     */
    public function toOccurrence(): array
    {
        return [
            'id'       => $this->id,
            'startsAt' => $this->startsAt->format(DATE_ATOM),
            'endsAt'   => $this->endsAt->format(DATE_ATOM),
            'seconds'  => $this->getDurationSeconds(),
        ];
    }
}
