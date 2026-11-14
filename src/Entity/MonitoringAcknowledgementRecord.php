<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmMonitoringAcknowledgementStore;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

/**
 * Since 1.26.0 (contract §24.2): a worker's acknowledgement of one monitoring policy version, the row of the default
 * {@see DoctrineOrmMonitoringAcknowledgementStore}. One row per user and version (the first one is kept). Always mapped
 * (an empty table while monitoring is off); deleted with the user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_monitoring_acknowledgements')]
#[ORM\UniqueConstraint(name: 'uniq_time_track_monitoring_ack_version', columns: ['user_id', 'policy_version'])]
class MonitoringAcknowledgementRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'acknowledged_at', type: 'datetime_immutable')]
    private DateTimeImmutable $acknowledgedAt;

    /**
     * Creates the row of an acknowledgement.
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'policy_version', type: 'integer')]
        private int $policyVersion,
        DateTimeImmutable $acknowledgedAt,
        #[ORM\Column(name: 'client_type', type: 'string', length: 16)]
        private string $clientType,
        #[ORM\Column(name: 'notice_sha256', type: 'string', length: 64, options: ['fixed' => true])]
        private string $noticeSha256,
    ) {
        $this->id             = Uuid::generateV7()->toString();
        $this->acknowledgedAt = StorageTime::normalize($acknowledgedAt);
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The worker. */
    public function getUser(): object
    {
        return $this->user;
    }

    /** The acknowledged version. */
    public function getPolicyVersion(): int
    {
        return $this->policyVersion;
    }

    /**
     * The framework-free value of the row.
     */
    public function toAcknowledgement(): MonitoringAcknowledgement
    {
        return new MonitoringAcknowledgement($this->policyVersion, $this->acknowledgedAt, $this->clientType, $this->noticeSha256);
    }
}
