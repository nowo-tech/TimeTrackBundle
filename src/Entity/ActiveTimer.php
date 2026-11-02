<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use const DATE_ATOM;

/**
 * Running timer of a user (one per user, unique index on user_id).
 */
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

    /**
     * Starts a timer (start time: the given one, else now).
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
        #[ORM\Column(name: 'client_type', type: 'string', length: 16, enumType: ClientType::class)]
        private ClientType $clientType,
        /** @var array<string, mixed> */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $metadata = null,
        ?DateTimeImmutable $startedAt = null,
    ) {
        $now                   = $startedAt ?? new DateTimeImmutable();
        $this->id              = Uuid::generate()->toString();
        $this->startedAt       = $now;
        $this->lastHeartbeatAt = $now;
    }

    /**
     * Timer id (UUID).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Timer owner.
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
     * Last heartbeat time.
     */
    public function getLastHeartbeatAt(): DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    /**
     * Client that started the timer.
     */
    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @phpstan-impure
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Records a heartbeat without any activity signal (1.9 client API).
     *
     * @phpstan-impure
     */
    public function touchHeartbeat(DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastHeartbeatAt = $at;

        return $this;
    }

    /**
     * Records a heartbeat and the client idle flag in the metadata (1.x; the 1.9 client API no longer sends idle).
     *
     * @phpstan-impure
     */
    public function heartbeat(bool $isIdle = false, ?DateTimeImmutable $at = null): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastHeartbeatAt = $at ?? new DateTimeImmutable();
        $metadata              = $this->metadata ?? [];
        $metadata['isIdle']    = $isIdle;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->metadata = $metadata;

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
