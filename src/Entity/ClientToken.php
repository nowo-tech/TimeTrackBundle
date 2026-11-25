<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use function array_filter;
use function array_unique;
use function array_values;
use function in_array;
use function is_string;
use function preg_match;

/**
 * Hashed Bearer token of a browser extension or desktop app session: a short-lived session token issued from a host
 * credential (`credentialId`, e.g. an app password) with a fixed scope set. Never listed to the user on its own; the
 * host lists and revokes the credential ({@see ClientTokenRevoker}).
 *
 * Since 2.2.0 (Beacon Desktop BD-2) a token belongs to one **scope family** ({@see DeviceScopeFamily}): `time` (every
 * token issued before 2.2, the password login and the default device flow) or a family the host registered (e.g.
 * `chat`). Only a `time` token opens the time client API. It may also carry the device key that groups the families of
 * one device and the name the device gave.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_client_tokens')]
#[ORM\Index(name: 'time_track_client_tokens_hash_idx', columns: ['token_hash'])]
#[ORM\Index(name: 'time_track_client_tokens_expires_idx', columns: ['expires_at'])]
#[ORM\Index(name: 'time_track_client_tokens_credential_idx', columns: ['credential_id'])]
class ClientToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    /** @var list<string>|null Scope values; null = issued before 1.9 (the default scopes, {@see ClientScope::defaults()}) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $scopes;

    /**
     * Creates a token (only the SHA-256 hash is stored).
     *
     * @param list<ClientScope|string>|null $scopes Granted scopes (null = the default scopes, the pre-1.13 set; unknown values are dropped). Since 2.2.0, for a family other than `time`, the scope values of that family (null = none)
     * @param string $scopeFamily Since 2.2.0: the scope family (default `time`)
     * @param string|null $deviceKey Since 2.2.0: the device key that groups the device's tokens
     * @param string|null $clientName Since 2.2.0: the name the device gave
     */
    public function __construct(
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private DateTimeImmutable $expiresAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'client_type', type: 'string', length: 16, enumType: ClientType::class)]
        private ClientType $clientType,
        ?DateTimeImmutable $createdAt = null,
        #[ORM\Column(name: 'credential_id', type: 'string', length: 191, nullable: true)]
        private ?string $credentialId = null,
        ?array $scopes = null,
        #[ORM\Column(name: 'scope_family', type: 'string', length: 32, options: ['default' => 'time'])]
        private string $scopeFamily = DeviceScopeFamily::TIME,
        #[ORM\Column(name: 'device_key', type: 'string', length: 36, nullable: true)]
        private ?string $deviceKey = null,
        #[ORM\Column(name: 'client_name', type: 'string', length: 100, nullable: true)]
        private ?string $clientName = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->scopes    = $this->scopeFamily === DeviceScopeFamily::TIME
            ? ($scopes === null ? null : ClientScope::values(ClientScope::normalize($scopes)))
            : $this->familyScopeValues($scopes ?? []);
    }

    /**
     * Since 2.2.0: the scope family of the token.
     */
    public function getScopeFamily(): string
    {
        return $this->scopeFamily;
    }

    /**
     * Since 2.2.0: whether the token belongs to the time family (only those open the time client API).
     */
    public function isTimeFamily(): bool
    {
        return $this->scopeFamily === DeviceScopeFamily::TIME;
    }

    /**
     * Since 2.2.0: the device key that groups the tokens of one device (null: issued before 2.2 or by a password login).
     */
    public function getDeviceKey(): ?string
    {
        return $this->deviceKey;
    }

    /**
     * Since 2.2.0: the name the device gave in the device flow (null when unknown).
     */
    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    /**
     * Since 2.2.0: the granted scope values of the token's family (the time scopes of a `time` token).
     *
     * @return list<string>
     */
    public function getScopeValues(): array
    {
        return $this->isTimeFamily() ? ClientScope::values($this->getScopes()) : $this->familyScopeValues($this->scopes ?? []);
    }

    /**
     * Since 2.2.0: whether the token grants the scope value (of any family).
     */
    public function hasScopeValue(string $scope): bool
    {
        return in_array($scope, $this->getScopeValues(), true);
    }

    /**
     * Host credential the token was issued from (null: a 1.x login whose authenticator returned no credential id).
     */
    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    /**
     * Granted time scopes (a token issued before 1.9 has the default scopes). Since 2.2.0 a token of another scope
     * family has none.
     *
     * @return list<ClientScope>
     */
    public function getScopes(): array
    {
        if (!$this->isTimeFamily()) {
            return [];
        }

        return $this->scopes === null ? ClientScope::defaults() : ClientScope::normalize($this->scopes);
    }

    /**
     * Whether the token grants the scope.
     */
    public function hasScope(ClientScope $scope): bool
    {
        return in_array($scope, $this->getScopes(), true);
    }

    /**
     * Issue time (same as the creation time): hosts compare it with "sign out everywhere".
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Token id (UUID).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * SHA-256 of the plain token.
     */
    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * Token owner.
     */
    public function getUser(): object
    {
        return $this->user;
    }

    /**
     * Client that holds the token.
     */
    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    /**
     * Expiry time.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Last time the token authenticated a request (refreshed every 5 minutes).
     */
    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * Whether the token is expired at the given time (default: now).
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    /**
     * Records a use of the token.
     */
    public function touch(?DateTimeImmutable $at = null): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastUsedAt = $at ?? new DateTimeImmutable();

        return $this;
    }

    /**
     * Well-formed scope values of a non-time family, without duplicates.
     *
     * @param array<mixed> $scopes
     *
     * @return list<string>
     */
    private function familyScopeValues(array $scopes): array
    {
        return array_values(array_unique(array_filter($scopes, static fn (mixed $scope): bool => is_string($scope) && preg_match(DeviceScopeFamily::SCOPE_PATTERN, $scope) === 1)));
    }
}
