<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

/**
 * Since 1.13: the timesheet of one user, one tenant scope and one period (contract §3.2). Rows are created lazily
 * (first view, first submit or auto-lock). The owner FK is nullable (`ON DELETE SET NULL`): `owner_ref` keeps the
 * identity for the retention term after account erasure (art. 17.3.b GDPR). Transitions go through the timesheet
 * service, which appends one audit row per change ({@see TimesheetEvent}); the mutators here are internal.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_timesheets')]
#[ORM\UniqueConstraint(name: 'uniq_time_track_timesheets_period', columns: ['user_id', 'scope_key', 'period_kind', 'period_start'])]
#[ORM\Index(name: 'idx_time_track_timesheets_queue', columns: ['scope_key', 'status', 'period_start'])]
#[ORM\Index(name: 'idx_time_track_timesheets_lock', columns: ['user_id', 'scope_key', 'locked_at', 'period_end'])]
// Since 1.15: the period lock of a deleted user, whose timesheets have only `owner_ref` left.
#[ORM\Index(name: 'idx_time_track_timesheets_owner_ref', columns: ['owner_ref', 'period_end'])]
class Timesheet
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'period_kind', type: 'string', length: 8, enumType: TimesheetPeriodKind::class)]
    private TimesheetPeriodKind $periodKind;

    #[ORM\Column(name: 'period_start', type: 'date_immutable')]
    private DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'period_end', type: 'date_immutable')]
    private DateTimeImmutable $periodEnd;

    #[ORM\Column(type: 'string', length: 16, enumType: TimesheetStatus::class)]
    private TimesheetStatus $status = TimesheetStatus::Open;

    /** Not mapped: an unstored view ({@see self::isDraft()}). */
    private bool $draft = false;

    #[ORM\Column(name: 'locked_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lockedAt = null;

    #[ORM\Column(name: 'lock_reason', type: 'string', length: 16, nullable: true, enumType: TimesheetLockReason::class)]
    private ?TimesheetLockReason $lockReason = null;

    #[ORM\Column(name: 'submitted_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $submittedAt = null;

    #[ORM\Column(name: 'submitted_by_ref', type: 'string', length: 64, nullable: true)]
    private ?string $submittedByRef = null;

    #[ORM\Column(name: 'decided_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'decided_by_ref', type: 'string', length: 64, nullable: true)]
    private ?string $decidedByRef = null;

    #[ORM\Column(name: 'decision_comment', type: 'string', length: 1000, nullable: true)]
    private ?string $decisionComment = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $totals = null;

    #[ORM\Column(name: 'entries_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $entriesHash = null;

    #[ORM\Column(name: 'head_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $headHash = null;

    #[ORM\Version]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * A new open timesheet of the period.
     *
     * @param object|null $user Owner (host user entity)
     * @param string $ownerRef Opaque owner id ({@see UserIdResolver::getId()})
     * @param string $scopeKey Tenant scope (`''` only with `timesheets.scope_mode: single`)
     * @param Period $period The period (kind, first and last local date)
     * @param string $timezone IANA zone the period is cut in
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $user,
        #[ORM\Column(name: 'owner_ref', type: 'string', length: 64)]
        private string $ownerRef,
        #[ORM\Column(name: 'scope_key', type: 'string', length: 64)]
        private string $scopeKey,
        Period $period,
        #[ORM\Column(type: 'string', length: 64)]
        private string $timezone,
        DateTimeInterface $now,
    ) {
        // Since 1.24.1 (REQ-SEC-006): a scope key a look-alike could stand for is refused, never trimmed.
        ScopeKeys::assertUsable($this->scopeKey);
        $this->id          = Uuid::generateV7()->toString();
        $this->periodKind  = $period->kind;
        $this->periodStart = Period::date($period->start);
        $this->periodEnd   = Period::date($period->end);
        $this->createdAt   = StorageTime::normalize($now);
        $this->updatedAt   = $this->createdAt;
    }

    /** Timesheet id (UUID v7). */
    public function getId(): string
    {
        return $this->id;
    }

    /** Owner (null after account erasure). */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /** Since 1.18: id of the live owner as a string (null after account erasure; {@see self::getOwnerRef()} stays). */
    public function getUserId(): ?string
    {
        return UserIdResolver::idOf($this->user);
    }

    /** Opaque owner id, kept after account erasure until the retention end. */
    public function getOwnerRef(): string
    {
        return $this->ownerRef;
    }

    /** Tenant scope key. */
    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    /** Period kind. */
    public function getPeriodKind(): TimesheetPeriodKind
    {
        return $this->periodKind;
    }

    /** The period (key and local dates). */
    public function getPeriod(): Period
    {
        return Period::containing($this->periodKind, $this->getPeriodStart());
    }

    /** First local date (Y-m-d). */
    public function getPeriodStart(): string
    {
        return $this->periodStart->format('Y-m-d');
    }

    /** Last local date (Y-m-d, inclusive). */
    public function getPeriodEnd(): string
    {
        return $this->periodEnd->format('Y-m-d');
    }

    /** IANA zone the period is cut in. */
    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /** Current status. */
    public function getStatus(): TimesheetStatus
    {
        return $this->status;
    }

    /** Whether the period is hard-locked (approved, auto or manual lock). */
    public function isLocked(): bool
    {
        return $this->lockedAt instanceof DateTimeImmutable;
    }

    /** When the period was locked (null = not locked). */
    public function getLockedAt(): ?DateTimeImmutable
    {
        return $this->lockedAt;
    }

    /** Why the period is locked (null = not locked). */
    public function getLockReason(): ?TimesheetLockReason
    {
        return $this->lockReason;
    }

    /** Last submit time. */
    public function getSubmittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /** Opaque id of the last submitter. */
    public function getSubmittedByRef(): ?string
    {
        return $this->submittedByRef;
    }

    /** Last approve / reject / reopen time. */
    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /** Opaque id of the last decider. */
    public function getDecidedByRef(): ?string
    {
        return $this->decidedByRef;
    }

    /** Comment of the last reject / reopen (cleared on the next submit). */
    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    /**
     * Snapshot at the last submit / approve (contract §5.8).
     *
     * @return array<string, mixed>|null
     */
    public function getTotals(): ?array
    {
        return $this->totals;
    }

    /** SHA-256 of the canonical entry list at the last submit / approve. */
    public function getEntriesHash(): ?string
    {
        return $this->entriesHash;
    }

    /** Hash of the last audit row (head of the chain). */
    public function getHeadHash(): ?string
    {
        return $this->headHash;
    }

    /** Optimistic lock version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Creation time. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Last change time. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Since the GB fixes: whether this is an unstored, open view of a period (a period the owner looked at but that has
     * no row: viewing never creates rows outside the previous and current periods). A draft has no audit trail and is
     * never persisted by the bundle.
     */
    public function isDraft(): bool
    {
        return $this->draft;
    }

    /**
     * Marks a new, unstored timesheet as a draft view.
     *
     * @internal
     */
    public function markDraft(): self
    {
        // @igor-ignore - Doctrine entity field (not mapped); instance-scoped, not a shared service.
        $this->draft = true;

        return $this;
    }

    /**
     * Whether an instant falls in the period (its local date in the timesheet's zone).
     */
    public function covers(DateTimeInterface $instant): bool
    {
        $date = StorageTime::localDate($instant, $this->timezone);

        return $date >= $this->getPeriodStart() && $date <= $this->getPeriodEnd();
    }

    /**
     * Records a transition: new status, the decision fields and the snapshot (internal: the timesheet service).
     *
     * @param array<string, mixed>|null $totals
     *
     * @internal
     */
    public function applySubmit(DateTimeInterface $at, string $actorRef, string $entriesHash, ?array $totals): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = TimesheetStatus::Submitted;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->submittedAt = StorageTime::normalize($at);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->submittedByRef = $actorRef;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->decisionComment = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->entriesHash = $entriesHash;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->totals = $totals;
        $this->touch($at);
    }

    /**
     * Back to open after a withdraw (internal).
     *
     * @internal
     */
    public function applyWithdraw(DateTimeInterface $at): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = TimesheetStatus::Open;
        $this->touch($at);
    }

    /**
     * Approved and hard-locked (internal).
     *
     * @param array<string, mixed>|null $totals
     *
     * @internal
     */
    public function applyApprove(DateTimeInterface $at, string $actorRef, string $entriesHash, ?array $totals): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = TimesheetStatus::Approved;
        $this->decide($at, $actorRef, null);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->entriesHash = $entriesHash;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->totals = $totals;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lockedAt ??= StorageTime::normalize($at);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lockReason = TimesheetLockReason::Approved;
    }

    /**
     * Rejected with a comment (internal).
     *
     * @internal
     */
    public function applyReject(DateTimeInterface $at, string $actorRef, string $comment): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = TimesheetStatus::Rejected;
        $this->decide($at, $actorRef, $comment);
    }

    /**
     * Unlocked and open again with a reason (internal).
     *
     * @internal
     */
    public function applyReopen(DateTimeInterface $at, string $actorRef, string $reason): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = TimesheetStatus::Open;
        $this->decide($at, $actorRef, $reason);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lockedAt = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lockReason = null;
    }

    /**
     * Locks the period without changing its status (auto-lock, manual lock; internal).
     *
     * @internal
     */
    public function applyLock(DateTimeInterface $at, TimesheetLockReason $reason): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lockedAt = StorageTime::normalize($at);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lockReason = $reason;
        $this->touch($at);
    }

    /**
     * Moves the head of the audit chain (internal: the audit trail, after appending a row).
     *
     * @internal
     */
    public function moveHead(string $hash, DateTimeInterface $at): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->headHash = $hash;
        $this->touch($at);
    }

    private function decide(DateTimeInterface $at, string $actorRef, ?string $comment): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->decidedAt = StorageTime::normalize($at);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->decidedByRef = $actorRef;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->decisionComment = $comment;
        $this->touch($at);
    }

    private function touch(DateTimeInterface $at): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = StorageTime::normalize($at);
    }
}
