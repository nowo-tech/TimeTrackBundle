<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use const DATE_ATOM;

#[ORM\Entity]
#[ORM\Table(name: 'time_track_entries')]
#[ORM\Index(name: 'time_track_entries_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'time_track_entries_task_idx', columns: ['task_id'])]
#[ORM\Index(name: 'time_track_entries_started_idx', columns: ['started_at'])]
class TimeEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
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
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUser(): object
    {
        return $this->user;
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function getTaskTitleSnapshot(): string
    {
        return $this->taskTitleSnapshot;
    }

    public function getBoardIdSnapshot(): ?string
    {
        return $this->boardIdSnapshot;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'taskId'          => $this->taskId,
            'taskTitle'       => $this->taskTitleSnapshot,
            'boardId'         => $this->boardIdSnapshot,
            'startedAt'       => $this->startedAt->format(DATE_ATOM),
            'endedAt'         => $this->endedAt->format(DATE_ATOM),
            'durationSeconds' => $this->durationSeconds,
            'source'          => $this->source->value,
            'metadata'        => $this->metadata,
        ];
    }
}
