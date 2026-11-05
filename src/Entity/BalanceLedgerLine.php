<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use const DATE_ATOM;

/**
 * Since 1.13: one append-only movement of the hours balance (contract §3.4): opening, adjustment, ratified / paid /
 * compensated overtime or a reversal of another line. Lines are never edited; each one is chained per
 * `(owner_ref, scope_key)` by `hash = sha256(prev_hash ‖ canonical_json(row without hash))`.
 *
 * The unique `(owner_ref, scope_key, prev_hash)` index stops two concurrent writers from forking a chain (the second
 * append fails and is reported as a conflict). `purged_before` is set by the retention purge on the first line it keeps:
 * the chain restarts there (its `prev_hash` names a purged line); it is not part of the hash.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_balance_ledger')]
#[ORM\Index(name: 'idx_time_track_balance_ledger_user', columns: ['user_id', 'scope_key', 'effective_date'])]
#[ORM\Index(name: 'idx_time_track_balance_ledger_timesheet', columns: ['timesheet_id'])]
#[ORM\Index(name: 'idx_time_track_balance_ledger_reverses', columns: ['reverses_id'])]
#[ORM\UniqueConstraint(name: 'uniq_time_track_balance_ledger_chain', columns: ['owner_ref', 'scope_key', 'prev_hash'])]
class BalanceLedgerLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'effective_date', type: 'date_immutable')]
    private DateTimeImmutable $effectiveDate;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 64, options: ['fixed' => true])]
    private string $hash;

    /**
     * Cut-off of the retention purge that made this line the start of its chain (null = never). Not hashed; written
     * by the purge with a DQL update only.
     */
    #[ORM\Column(name: 'purged_before', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $purgedBefore = null; // @phpstan-ignore property.unusedType

    /**
     * Builds the line and computes its hash.
     *
     * @param string $effectiveDate Local date the movement applies to (Y-m-d)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $user,
        #[ORM\Column(name: 'owner_ref', type: 'string', length: 64)]
        private string $ownerRef,
        #[ORM\Column(name: 'scope_key', type: 'string', length: 64)]
        private string $scopeKey,
        string $effectiveDate,
        #[ORM\Column(type: 'string', length: 24, enumType: LedgerKind::class)]
        private LedgerKind $kind,
        #[ORM\Column(type: 'integer')]
        private int $seconds,
        DateTimeInterface $createdAt,
        #[ORM\Column(name: 'prev_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
        private ?string $prevHash,
        #[ORM\Column(type: 'string', length: 500, nullable: true)]
        private ?string $reason = null,
        #[ORM\ManyToOne(targetEntity: Timesheet::class)]
        #[ORM\JoinColumn(name: 'timesheet_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        private ?Timesheet $timesheet = null,
        #[ORM\Column(name: 'reverses_id', type: 'string', length: 36, nullable: true)]
        private ?string $reversesId = null,
        #[ORM\Column(name: 'actor_ref', type: 'string', length: 64, nullable: true)]
        private ?string $actorRef = null,
    ) {
        $this->id            = Uuid::generateV7()->toString();
        $this->effectiveDate = Period::date($effectiveDate);
        $this->createdAt     = StorageTime::normalize($createdAt);
        $this->hash          = $this->computeHash();
    }

    /**
     * The hash the line should have from its stored fields.
     */
    public function computeHash(): string
    {
        return CanonicalJson::hash($this->canonicalRow(), $this->prevHash ?? '');
    }

    /**
     * The hashed fields (everything but `hash`). Lines and their timesheets share one retention term and are purged
     * by the same command, so the timesheet link is hashed as stored.
     *
     * @return array<string, mixed>
     */
    public function canonicalRow(): array
    {
        return [
            'id'            => $this->id,
            'ownerRef'      => $this->ownerRef,
            'scopeKey'      => $this->scopeKey,
            'effectiveDate' => $this->getEffectiveDate(),
            'kind'          => $this->kind->value,
            'seconds'       => $this->seconds,
            'reason'        => $this->reason,
            'timesheetId'   => $this->timesheet?->getId(),
            'reversesId'    => $this->reversesId,
            'actorRef'      => $this->actorRef,
            'createdAt'     => StorageTime::utc($this->createdAt),
            'prevHash'      => $this->prevHash,
        ];
    }

    /** Line id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Owner (null after account erasure). */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /** Opaque owner id. */
    public function getOwnerRef(): string
    {
        return $this->ownerRef;
    }

    /** Tenant scope key. */
    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    /** Local date the movement applies to (Y-m-d). */
    public function getEffectiveDate(): string
    {
        return $this->effectiveDate->format('Y-m-d');
    }

    /** Kind. */
    public function getKind(): LedgerKind
    {
        return $this->kind;
    }

    /** Signed seconds. */
    public function getSeconds(): int
    {
        return $this->seconds;
    }

    /** Reason (required for opening, adjustment, overtime_paid, reversal). */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /** The approved timesheet of an `overtime_ratified` line (null once purged). */
    public function getTimesheet(): ?Timesheet
    {
        return $this->timesheet;
    }

    /** Id of the reversed line (`reversal` only). */
    public function getReversesId(): ?string
    {
        return $this->reversesId;
    }

    /** Opaque actor id (null = system). */
    public function getActorRef(): ?string
    {
        return $this->actorRef;
    }

    /** Creation time. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Hash of the previous line of the owner and scope (null for the first). */
    public function getPrevHash(): ?string
    {
        return $this->prevHash;
    }

    /** Stored hash. */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Cut-off (Y-m-d) of the retention purge that made this line the start of its chain, or null.
     */
    public function getPurgedBefore(): ?string
    {
        return $this->purgedBefore?->format('Y-m-d');
    }

    /**
     * Client shape: no owner or actor identity beyond the opaque refs the viewer may see.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'scopeKey'      => $this->scopeKey,
            'effectiveDate' => $this->getEffectiveDate(),
            'kind'          => $this->kind->value,
            'seconds'       => $this->seconds,
            'reason'        => $this->reason,
            'timesheetId'   => $this->timesheet?->getId(),
            'reversesId'    => $this->reversesId,
            'createdAt'     => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
