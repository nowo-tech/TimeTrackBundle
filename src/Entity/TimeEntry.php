<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use const DATE_ATOM;

/**
 * Closed time entry: task snapshot, start, end, duration and source. Since 1.11 an entry may also be imported from
 * another tool ({@see TimeEntrySource::Import}): it then carries an external identity (unique pair), and is read-only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_entries')]
#[ORM\Index(name: 'time_track_entries_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'time_track_entries_task_idx', columns: ['task_id'])]
#[ORM\Index(name: 'time_track_entries_started_idx', columns: ['started_at'])]
// Since 1.11: keyset purges and reports by end time (same name and columns as Beacon's own migration, so adopting it is a no-op).
#[ORM\Index(name: 'time_track_entries_ended_idx', columns: ['ended_at', 'id'])]
#[ORM\Index(name: 'idx_time_track_entries_source', columns: ['source'])]
#[ORM\UniqueConstraint(name: 'uniq_time_track_entries_external', columns: ['external_source', 'external_id'])]
class TimeEntry
{
    /**
     * Title shown instead of the snapshot of an imported entry whose task the owner cannot see, when no translator is
     * available (the client API translates `reports.task_not_visible` when it has one).
     */
    public const HIDDEN_TASK_TITLE = 'Task not visible';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** Since 1.11: the importing system and its scope, e.g. "clickup:<directory id>" (null for live entries). */
    #[ORM\Column(name: 'external_source', type: 'string', length: 64, nullable: true)]
    private ?string $externalSource = null;

    /** Since 1.11: the entry's id in the importing system (unique with the source). */
    #[ORM\Column(name: 'external_id', type: 'string', length: 128, nullable: true)]
    private ?string $externalId = null;

    /** Since 1.11: billable flag when the source tool has one. */
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $billable = null;

    /** Since 1.11: plain-text description, kept in full (no length cap). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Creates a time entry.
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'task_id', type: 'string', length: 36)]
        private string $taskId,
        #[ORM\Column(name: 'task_title_snapshot', type: 'string', length: 255)]
        private string $taskTitleSnapshot,
        #[ORM\Column(name: 'board_id_snapshot', type: 'string', length: 36, nullable: true)]
        private ?string $boardIdSnapshot,
        #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
        private DateTimeImmutable $startedAt,
        #[ORM\Column(name: 'ended_at', type: 'datetime_immutable')]
        private DateTimeImmutable $endedAt,
        #[ORM\Column(name: 'duration_seconds', type: 'integer')]
        private int $durationSeconds,
        #[ORM\Column(type: 'string', length: 16, enumType: TimeEntrySource::class)]
        private TimeEntrySource $source,
        /** @var array<string, mixed> */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $metadata = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * Since 1.11: a new imported entry ({@see TimeEntrySource::Import}) from validated data; use
     * {@see ImportedTimeEntryWriter}, which validates and deduplicates.
     *
     * @internal
     */
    public static function imported(object $user, ImportedTimeEntryData $data): self
    {
        $entry = new self($user, $data->taskId, $data->taskTitle, $data->boardId, $data->startedAt, $data->endedAt, $data->durationSeconds, TimeEntrySource::Import);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->externalSource = $data->externalSource;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->externalId = $data->externalId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->billable = $data->billable;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->description = $data->description;

        return $entry;
    }

    /**
     * Since 1.11: applies newer imported data (same external identity); true when a field changed.
     *
     * @internal used by the imported-entry writer only
     */
    public function applyImport(ImportedTimeEntryData $data): bool
    {
        $before = [$this->taskId, $this->taskTitleSnapshot, $this->boardIdSnapshot, $this->startedAt->getTimestamp(), $this->endedAt->getTimestamp(), $this->durationSeconds, $this->billable, $this->description];
        $after  = [$data->taskId, $data->taskTitle, $data->boardId, $data->startedAt->getTimestamp(), $data->endedAt->getTimestamp(), $data->durationSeconds, $data->billable, $data->description];
        if ($before === $after) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskId = $data->taskId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskTitleSnapshot = $data->taskTitle;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->boardIdSnapshot = $data->boardId;
        if ($this->startedAt->getTimestamp() !== $data->startedAt->getTimestamp()) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->startedAt = $data->startedAt;
        }
        if ($this->endedAt->getTimestamp() !== $data->endedAt->getTimestamp()) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->endedAt = $data->endedAt;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->durationSeconds = $data->durationSeconds;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->billable = $data->billable;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $data->description;

        return true;
    }

    /**
     * Entry id (UUID).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Entry owner.
     */
    public function getUser(): object
    {
        return $this->user;
    }

    /**
     * Tracked task id.
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * Task title when the timer started.
     */
    public function getTaskTitleSnapshot(): string
    {
        return $this->taskTitleSnapshot;
    }

    /**
     * Board id when the timer started.
     */
    public function getBoardIdSnapshot(): ?string
    {
        return $this->boardIdSnapshot;
    }

    /**
     * Start time.
     */
    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * End time.
     */
    public function getEndedAt(): DateTimeImmutable
    {
        return $this->endedAt;
    }

    /**
     * Duration in seconds.
     */
    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    /**
     * Client that recorded the entry.
     */
    public function getSource(): TimeEntrySource
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Since 1.11: the importing system and scope (null for live entries).
     */
    public function getExternalSource(): ?string
    {
        return $this->externalSource;
    }

    /**
     * Since 1.11: the entry's id in the importing system (null for live entries).
     */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * Since 1.11: billable flag (null = unknown).
     */
    public function getBillable(): ?bool
    {
        return $this->billable;
    }

    /**
     * Since 1.11: plain-text description (null = none).
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Since 1.11: imported entries are read-only (they can only be deleted).
     */
    public function isReadOnly(): bool
    {
        return $this->source->isReadOnly();
    }

    /**
     * Creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Client API shape. Since 1.11 it also has `billable`, `description`, `readOnly` and `taskVisible`; with
     * `$taskVisible` false the title is `$hiddenTitle` (the translated `reports.task_not_visible` in the client API,
     * {@see self::HIDDEN_TASK_TITLE} by default): imported entries of a task the owner cannot see. The external identity
     * is never exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $taskVisible = true, string $hiddenTitle = self::HIDDEN_TASK_TITLE): array
    {
        return [
            'id'              => $this->id,
            'taskId'          => $this->taskId,
            'taskTitle'       => $taskVisible ? $this->taskTitleSnapshot : $hiddenTitle,
            'boardId'         => $this->boardIdSnapshot,
            'startedAt'       => $this->startedAt->format(DATE_ATOM),
            'endedAt'         => $this->endedAt->format(DATE_ATOM),
            'durationSeconds' => $this->durationSeconds,
            'source'          => $this->source->value,
            'metadata'        => $this->metadata,
            'billable'        => $this->billable,
            'description'     => $this->description,
            'readOnly'        => $this->isReadOnly(),
            'taskVisible'     => $taskVisible,
        ];
    }
}
