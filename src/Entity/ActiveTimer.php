<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use const DATE_ATOM;

#[ORM\Entity]
#[ORM\Table(name: 'time_track_active_timers')]
#[ORM\UniqueConstraint(name: 'time_track_active_timers_user_unique', columns: ['user_id'])]
class ActiveTimer
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'last_heartbeat_at', type: 'datetime_immutable')]
    private DateTimeImmutable $lastHeartbeatAt;

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
        #[ORM\Column(name: 'client_type', type: 'string', length: 16, enumType: ClientType::class)]
        private ClientType $clientType,
        /** @var array<string, mixed> */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $metadata = null,
    ) {
        $now                   = new DateTimeImmutable();
        $this->id              = Uuid::generate()->toString();
        $this->startedAt       = $now;
        $this->lastHeartbeatAt = $now;
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

    public function getLastHeartbeatAt(): DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function heartbeat(bool $isIdle = false): self
    {
        $this->lastHeartbeatAt = new DateTimeImmutable();
        $metadata              = $this->metadata ?? [];
        $metadata['isIdle']    = $isIdle;
        $this->metadata        = $metadata;

        return $this;
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
            'lastHeartbeatAt' => $this->lastHeartbeatAt->format(DATE_ATOM),
            'clientType'      => $this->clientType->value,
            'metadata'        => $this->metadata,
        ];
    }
}
