<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

/**
 * Hashed Bearer token of a browser extension or desktop app session.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_client_tokens')]
#[ORM\Index(name: 'time_track_client_tokens_hash_idx', columns: ['token_hash'])]
#[ORM\Index(name: 'time_track_client_tokens_expires_idx', columns: ['expires_at'])]
class ClientToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    /**
     * Creates a token (only the SHA-256 hash is stored).
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
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
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
}
