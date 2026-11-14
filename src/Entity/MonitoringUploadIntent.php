<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

/**
 * Since 1.26.0 (contract §24.3): a screenshot upload the bundle granted (`POST monitoring/screenshots/intent`) — the
 * declared image (entry, slot, time, policy version, MIME, size, dimensions, SHA-256), the hashed `Idempotency-Key`
 * and body of the request, and the state of its one-shot signed URL. The image itself is never stored here.
 *
 * States: `pending` (the URL signed until `signedUntil` is valid), `uploading` (a PUT consumed it and is streaming),
 * `spent` (that PUT failed; the URL is dead and a replay of the intent signs one that expires later), `stored` (the host
 * stored it as `screenshotId`). Rows are purged 24 h after creation (the replay window) by
 * `nowo:time-track:client-tokens:purge` and when the user asks for a new intent; deleted with the user.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_monitoring_upload_intents')]
#[ORM\UniqueConstraint(name: 'uniq_time_track_monitoring_intent_key', columns: ['user_id', 'idempotency_hash'])]
#[ORM\Index(name: 'idx_time_track_monitoring_intent_slot', columns: ['user_id', 'entry_id', 'slot'])]
#[ORM\Index(name: 'idx_time_track_monitoring_intent_created', columns: ['created_at'])]
class MonitoringUploadIntent
{
    /** A URL may be signed. */
    public const STATUS_PENDING = 'pending';

    /** A PUT consumed the URL and is streaming the bytes. */
    public const STATUS_UPLOADING = 'uploading';

    /** The host stored the screenshot. */
    public const STATUS_STORED = 'stored';

    /** A PUT failed after consuming the URL: the URL is dead; a replay of the intent signs a later one. */
    public const STATUS_SPENT = 'spent';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'signed_until', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $signedUntil = null;

    /** Set by a conditional DQL update when the host stored the screenshot. */
    #[ORM\Column(name: 'screenshot_id', type: 'string', length: 64, nullable: true)]
    private ?string $screenshotId = null; // @phpstan-ignore property.unusedType

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $slot;

    #[ORM\Column(name: 'taken_at', type: 'datetime_immutable')]
    private DateTimeImmutable $takenAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Creates a pending intent (the controller checked every field and refusal first).
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'idempotency_hash', type: 'string', length: 64, options: ['fixed' => true])]
        private string $idempotencyHash,
        #[ORM\Column(name: 'body_hash', type: 'string', length: 64, options: ['fixed' => true])]
        private string $bodyHash,
        #[ORM\Column(name: 'entry_id', type: 'string', length: 36)]
        private string $entryId,
        DateTimeImmutable $slot,
        DateTimeImmutable $takenAt,
        #[ORM\Column(name: 'policy_version', type: 'integer')]
        private int $policyVersion,
        #[ORM\Column(type: 'boolean')]
        private bool $blurred,
        #[ORM\Column(type: 'string', length: 16)]
        private string $mime,
        #[ORM\Column(type: 'integer')]
        private int $bytes,
        #[ORM\Column(type: 'integer')]
        private int $width,
        #[ORM\Column(type: 'integer')]
        private int $height,
        #[ORM\Column(type: 'string', length: 64, options: ['fixed' => true])]
        private string $sha256,
        DateTimeImmutable $now,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->slot      = StorageTime::normalize($slot);
        $this->takenAt   = StorageTime::normalize($takenAt);
        $this->createdAt = StorageTime::normalize($now);
    }

    /** The upload id (random UUID v4). */
    public function getId(): string
    {
        return $this->id;
    }

    /** The worker. */
    public function getUser(): object
    {
        return $this->user;
    }

    /** SHA-256 of the user's `Idempotency-Key`. */
    public function getIdempotencyHash(): string
    {
        return $this->idempotencyHash;
    }

    /** SHA-256 of the canonical request body. */
    public function getBodyHash(): string
    {
        return $this->bodyHash;
    }

    /** `pending`, `uploading`, `spent` or `stored`. */
    public function getStatus(): string
    {
        return $this->status;
    }

    /** Expiry of the URL signed last. */
    public function getSignedUntil(): ?DateTimeImmutable
    {
        return $this->signedUntil;
    }

    /** The host's screenshot id once stored. */
    public function getScreenshotId(): ?string
    {
        return $this->screenshotId;
    }

    /** The entry (running timer or recorded entry) id the client sent. */
    public function getEntryId(): string
    {
        return $this->entryId;
    }

    /** Start of the slot. */
    public function getSlot(): DateTimeImmutable
    {
        return $this->slot;
    }

    /** Capture time. */
    public function getTakenAt(): DateTimeImmutable
    {
        return $this->takenAt;
    }

    /** Policy version the capture was taken under. */
    public function getPolicyVersion(): int
    {
        return $this->policyVersion;
    }

    /** Whether the client blurred the image. */
    public function isBlurred(): bool
    {
        return $this->blurred;
    }

    /** Declared MIME type. */
    public function getMime(): string
    {
        return $this->mime;
    }

    /** Declared size in bytes. */
    public function getBytes(): int
    {
        return $this->bytes;
    }

    /** Declared width in pixels. */
    public function getWidth(): int
    {
        return $this->width;
    }

    /** Declared height in pixels. */
    public function getHeight(): int
    {
        return $this->height;
    }

    /** Declared lowercase hex SHA-256. */
    public function getSha256(): string
    {
        return $this->sha256;
    }

    /** Creation time. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Records the expiry of a newly signed URL.
     */
    public function signUntil(DateTimeImmutable $until): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->signedUntil = StorageTime::normalize($until);

        return $this;
    }
}
