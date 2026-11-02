<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

/**
 * Device authorization request of the device flow (RFC 8628 shape). Device code and user code are stored as SHA-256
 * hashes only; the request is single use (approved → consumed on the first token exchange) and expires after
 * `client_api.device_flow.code_ttl`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_device_codes')]
#[ORM\Index(name: 'time_track_device_codes_user_code_idx', columns: ['user_code_hash'])]
#[ORM\Index(name: 'time_track_device_codes_expires_idx', columns: ['expires_at'])]
class DeviceAuthorization
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 16, enumType: DeviceAuthorizationStatus::class)]
    private DeviceAuthorizationStatus $status = DeviceAuthorizationStatus::Pending;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?object $user = null;

    #[ORM\Column(name: 'credential_id', type: 'string', length: 191, nullable: true)]
    private ?string $credentialId = null;

    #[ORM\Column(name: 'last_polled_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastPolledAt = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes;

    /**
     * Creates a pending request.
     *
     * @param list<ClientScope> $scopes Requested scopes
     * @param string|null $ipPrefix IPv4 /24 or IPv6 /64 of the requesting device (shown on the approval page)
     */
    public function __construct(
        #[ORM\Column(name: 'device_code_hash', type: 'string', length: 64, unique: true)]
        private string $deviceCodeHash,
        #[ORM\Column(name: 'user_code_hash', type: 'string', length: 64)]
        private string $userCodeHash,
        #[ORM\Column(name: 'client_type', type: 'string', length: 16, enumType: ClientType::class)]
        private ClientType $clientType,
        #[ORM\Column(name: 'client_name', type: 'string', length: 100)]
        private string $clientName,
        array $scopes,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'ip_prefix', type: 'string', length: 64, nullable: true)]
        private ?string $ipPrefix = null,
    ) {
        $this->id     = Uuid::generate()->toString();
        $this->scopes = ClientScope::values($scopes);
    }

    /** Request id (UUID). */
    public function getId(): string
    {
        return $this->id;
    }

    /** SHA-256 of the device code. */
    public function getDeviceCodeHash(): string
    {
        return $this->deviceCodeHash;
    }

    /** SHA-256 of the normalised user code. */
    public function getUserCodeHash(): string
    {
        return $this->userCodeHash;
    }

    /** Client asking for access. */
    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    /** IPv4 /24 or IPv6 /64 of the device that started the request (null when unknown). */
    public function getIpPrefix(): ?string
    {
        return $this->ipPrefix;
    }

    /** Name the client sent (shown to the user before approval). */
    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * Requested scopes.
     *
     * @return list<ClientScope>
     */
    public function getScopes(): array
    {
        return ClientScope::normalize($this->scopes);
    }

    /** Current state. */
    public function getStatus(): DeviceAuthorizationStatus
    {
        return $this->status;
    }

    /** User who decided (null while pending). */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /** Credential issued on approval. */
    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    /** Expiry time. */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Creation time. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Last token poll of the device. */
    public function getLastPolledAt(): ?DateTimeImmutable
    {
        return $this->lastPolledAt;
    }

    /** Whether the request expired at the given time. */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * Marks the request approved by the user with the credential the host issued.
     */
    public function approve(object $user, string $credentialId): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = DeviceAuthorizationStatus::Approved;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->user = $user;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->credentialId = $credentialId;
    }

    /**
     * Marks the request denied by the user.
     */
    public function deny(object $user): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = DeviceAuthorizationStatus::Denied;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->user = $user;
    }

    /**
     * Records a token poll.
     */
    public function polled(DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastPolledAt = $at;
    }
}
