<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use function array_filter;
use function array_unique;
use function array_values;
use function is_string;
use function preg_match;

/**
 * Device authorization request of the device flow (RFC 8628 shape). Device code and user code are stored as SHA-256
 * hashes only; the request is single use (approved → consumed on the first token exchange) and expires after
 * `client_api.device_flow.code_ttl`.
 *
 * Since 2.2.0 a request names one scope family ({@see DeviceScopeFamily}) and may carry the device key of a token the
 * device already holds (proven with its Bearer token), so the new token joins that device's row.
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
     * @param list<ClientScope|string> $scopes Requested scopes (since 2.2.0 the scope values of `$scopeFamily`)
     * @param string|null $ipPrefix IPv4 /24 or IPv6 /64 of the requesting device (shown on the approval page)
     * @param string $scopeFamily Since 2.2.0: the requested scope family (default `time`)
     * @param string|null $deviceKey Since 2.2.0: the device key the issued token gets
     * @param string|null $linkOwnerId Since 2.2.0: the user id of the token the device presented (null = none)
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
        #[ORM\Column(name: 'scope_family', type: 'string', length: 32, options: ['default' => 'time'])]
        private string $scopeFamily = DeviceScopeFamily::TIME,
        #[ORM\Column(name: 'device_key', type: 'string', length: 36, nullable: true)]
        private ?string $deviceKey = null,
        // Since 2.2.0 (audit ZBV-M1): only this user may approve; compared in PHP (===), never in SQL.
        #[ORM\Column(name: 'link_owner_id', type: 'string', length: 191, nullable: true)]
        private ?string $linkOwnerId = null,
    ) {
        $this->id     = Uuid::generate()->toString();
        $this->scopes = $this->scopeFamily === DeviceScopeFamily::TIME
            ? ClientScope::values(ClientScope::normalize($scopes))
            : array_values(array_unique(array_filter($scopes, static fn (mixed $scope): bool => is_string($scope) && preg_match(DeviceScopeFamily::SCOPE_PATTERN, $scope) === 1)));
    }

    /** Since 2.2.0: the requested scope family. */
    public function getScopeFamily(): string
    {
        return $this->scopeFamily;
    }

    /** Since 2.2.0 (audit ZBV-M1): the user id of the token the device presented, or null. */
    public function getLinkOwnerId(): ?string
    {
        return $this->linkOwnerId;
    }

    /** Since 2.2.0: the device key the issued token gets. */
    public function getDeviceKey(): ?string
    {
        return $this->deviceKey;
    }

    /**
     * Since 2.2.0: the requested scope values of the request's family.
     *
     * @return list<string>
     */
    public function getScopeValues(): array
    {
        return $this->scopeFamily === DeviceScopeFamily::TIME ? ClientScope::values($this->getScopes()) : $this->scopes;
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
     * Requested time scopes (since 2.2.0 none for a request of another scope family).
     *
     * @return list<ClientScope>
     */
    public function getScopes(): array
    {
        return $this->scopeFamily === DeviceScopeFamily::TIME ? ClientScope::normalize($this->scopes) : [];
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
     *
     * @param string|null $deviceKey Since 2.2.0: the device key given to the host (kept when null)
     */
    public function approve(object $user, string $credentialId, ?string $deviceKey = null): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->deviceKey = $deviceKey ?? $this->deviceKey;
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
