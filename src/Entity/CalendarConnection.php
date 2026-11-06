<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionStatus;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use function substr;

use const DATE_ATOM;

/**
 * Since 1.14: a user's opt-in to calendar suggestions for one tenant scope and one provider (contract §3.5). Personal
 * data with no legal retention: deleted with the account (FK `ON DELETE CASCADE`) and on revoke. The token is never
 * here: `credential_ref` is the host's opaque id of its encrypted store (server mode); local mode keeps no reference.
 * Only error **codes** are stored, never a provider message.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_calendar_connections')]
#[ORM\UniqueConstraint(name: 'uniq_time_track_calendar_connections', columns: ['user_id', 'scope_key', 'provider'])]
#[ORM\Index(name: 'idx_time_track_calendar_connections_due', columns: ['mode', 'status', 'next_attempt_at'])]
class CalendarConnection
{
    /** Longest stored error code. */
    public const ERROR_CODE_MAX_LENGTH = 32;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 16, enumType: CalendarConnectionStatus::class)]
    private CalendarConnectionStatus $status = CalendarConnectionStatus::Active;

    #[ORM\Column(name: 'consented_at', type: 'datetime_immutable')]
    private DateTimeImmutable $consentedAt;

    #[ORM\Column(name: 'last_sync_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(name: 'last_error_code', type: 'string', length: 32, nullable: true)]
    private ?string $lastErrorCode = null;

    /** Since the GG fixes: last sync attempt, successful or not (orders the scheduled run). */
    #[ORM\Column(name: 'last_attempt_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastAttemptAt = null;

    /** Since the GG fixes: earliest next scheduled sync (interval after a success, backoff after failures). */
    #[ORM\Column(name: 'next_attempt_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $nextAttemptAt = null;

    /** Since the GG fixes: failed syncs in a row (backoff). */
    #[ORM\Column(name: 'failure_count', type: 'integer', options: ['default' => 0])]
    private int $failureCount = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * Records the consent (status `active`).
     *
     * @param string|null $credentialRef Host id of the encrypted token (server mode); null in local mode
     * @param string $consentVersion Version of the notice the user accepted
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private object $user,
        #[ORM\Column(name: 'scope_key', type: 'string', length: 64)]
        private string $scopeKey,
        #[ORM\Column(type: 'string', length: 16, enumType: CalendarProvider::class)]
        private CalendarProvider $provider,
        #[ORM\Column(type: 'string', length: 8, enumType: CalendarConnectionMode::class)]
        private CalendarConnectionMode $mode,
        #[ORM\Column(name: 'credential_ref', type: 'string', length: 128, nullable: true)]
        private ?string $credentialRef,
        #[ORM\Column(name: 'consent_version', type: 'string', length: 16)]
        private string $consentVersion,
        DateTimeImmutable $now,
    ) {
        $this->id          = Uuid::generateV7()->toString();
        $this->consentedAt = StorageTime::normalize($now);
        $this->createdAt   = $this->consentedAt;
        $this->updatedAt   = $this->consentedAt;
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The owner. */
    public function getUser(): object
    {
        return $this->user;
    }

    /** Tenant scope the suggestions resolve tasks in. */
    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    /** Calendar provider. */
    public function getProvider(): CalendarProvider
    {
        return $this->provider;
    }

    /** Server or local mode. */
    public function getMode(): CalendarConnectionMode
    {
        return $this->mode;
    }

    /** Host id of the encrypted token (null in local mode). */
    public function getCredentialRef(): ?string
    {
        return $this->credentialRef;
    }

    /** Current state. */
    public function getStatus(): CalendarConnectionStatus
    {
        return $this->status;
    }

    /** Version of the accepted notice. */
    public function getConsentVersion(): string
    {
        return $this->consentVersion;
    }

    /** When the consent was given (or renewed). */
    public function getConsentedAt(): DateTimeImmutable
    {
        return $this->consentedAt;
    }

    /** Last successful sync. */
    public function getLastSyncAt(): ?DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    /** Code of the last failed sync (null after a success). */
    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    /** Last sync attempt. */
    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    /** Earliest next scheduled sync (null = due now). */
    public function getNextAttemptAt(): ?DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    /** Failed syncs in a row. */
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    /** Creation time. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Last change. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Renews the consent and the credential reference (a reconnect): back to `active`, error cleared.
     *
     * @internal use the calendar connection service
     */
    public function reconnect(CalendarConnectionMode $mode, ?string $credentialRef, string $consentVersion, DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mode = $mode;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->credentialRef = $credentialRef;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->consentVersion = $consentVersion;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->consentedAt = StorageTime::normalize($now);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastErrorCode = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->failureCount = 0;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextAttemptAt = null;
        $this->changeStatus(CalendarConnectionStatus::Active, $now);
    }

    /**
     * Records a successful sync (status `active`, error cleared) and the next scheduled one.
     *
     * @internal used by the calendar sync service
     */
    public function markSynced(DateTimeImmutable $now, ?DateTimeImmutable $nextAttempt = null): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastSyncAt = StorageTime::normalize($now);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastAttemptAt = $this->lastSyncAt;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextAttemptAt = $nextAttempt instanceof DateTimeImmutable ? StorageTime::normalize($nextAttempt) : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->failureCount = 0;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastErrorCode = null;
        $this->changeStatus(CalendarConnectionStatus::Active, $now);
    }

    /**
     * Records a failed sync: status `error`, the code (cut to 32 characters, never a message), one more failure in a row
     * and the next attempt (the caller's backoff).
     *
     * @internal used by the calendar sync service
     */
    public function markFailed(string $errorCode, DateTimeImmutable $now, ?DateTimeImmutable $nextAttempt = null): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastErrorCode = substr($errorCode, 0, self::ERROR_CODE_MAX_LENGTH);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastAttemptAt = StorageTime::normalize($now);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->nextAttemptAt = $nextAttempt instanceof DateTimeImmutable ? StorageTime::normalize($nextAttempt) : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        ++$this->failureCount;
        $this->changeStatus(CalendarConnectionStatus::Error, $now);
    }

    /**
     * Pauses (no sync) or resumes the connection.
     *
     * @internal use the calendar connection service
     */
    public function changeStatus(CalendarConnectionStatus $status, DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = $status;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = StorageTime::normalize($now);
    }

    /**
     * Client API shape (`GET calendar/connections`): no credential reference.
     *
     * @return array{id: string, provider: string, mode: string, status: string, scopeKey: string, lastSyncAt: string|null, lastErrorCode: string|null, consentVersion: string, consentedAt: string}
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'provider'       => $this->provider->value,
            'mode'           => $this->mode->value,
            'status'         => $this->status->value,
            'scopeKey'       => $this->scopeKey,
            'lastSyncAt'     => $this->lastSyncAt?->format(DATE_ATOM),
            'lastErrorCode'  => $this->lastErrorCode,
            'consentVersion' => $this->consentVersion,
            'consentedAt'    => $this->consentedAt->format(DATE_ATOM),
        ];
    }
}
