<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

/**
 * Since 1.13: one immutable audit row of a timesheet (contract §3.3): who did what, when, with which comment and
 * snapshot, chained to the previous row by `hash = sha256(prev_hash ‖ canonical_json(row without hash))`. The bundle
 * never updates or deletes these rows (only the retention purge deletes them, with their timesheet).
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_timesheet_events')]
#[ORM\UniqueConstraint(name: 'uniq_time_track_timesheet_events_seq', columns: ['timesheet_id', 'seq'])]
class TimesheetEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'string', length: 64, options: ['fixed' => true])]
    private string $hash;

    /**
     * Builds the row and computes its hash.
     *
     * @param array<string, mixed>|null $totals
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Timesheet::class)]
        #[ORM\JoinColumn(name: 'timesheet_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private Timesheet $timesheet,
        #[ORM\Column(type: 'integer')]
        private int $seq,
        #[ORM\Column(type: 'string', length: 24, enumType: TimesheetAction::class)]
        private TimesheetAction $action,
        #[ORM\Column(name: 'from_status', type: 'string', length: 16, enumType: TimesheetStatus::class)]
        private TimesheetStatus $fromStatus,
        #[ORM\Column(name: 'to_status', type: 'string', length: 16, enumType: TimesheetStatus::class)]
        private TimesheetStatus $toStatus,
        #[ORM\Column(name: 'actor_ref', type: 'string', length: 64, nullable: true)]
        private ?string $actorRef,
        #[ORM\Column(name: 'actor_role', type: 'string', length: 16, enumType: TimesheetActorRole::class)]
        private TimesheetActorRole $actorRole,
        #[ORM\Column(type: 'string', length: 16, enumType: AuditClient::class)]
        private AuditClient $client,
        DateTimeInterface $occurredAt,
        #[ORM\Column(name: 'prev_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
        private ?string $prevHash,
        #[ORM\Column(type: 'string', length: 1000, nullable: true)]
        private ?string $comment = null,
        #[ORM\Column(name: 'entries_hash', type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
        private ?string $entriesHash = null,
        /** @var array<string, mixed>|null */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $totals = null,
    ) {
        $this->id         = Uuid::generateV7()->toString();
        $this->occurredAt = StorageTime::normalize($occurredAt);
        $this->hash       = $this->computeHash();
    }

    /**
     * The hash the row should have from its stored fields (verification: compare with {@see self::getHash()}).
     */
    public function computeHash(): string
    {
        return CanonicalJson::hash($this->canonicalRow(), $this->prevHash ?? '');
    }

    /**
     * The hashed fields (everything but `hash`), canonical forms.
     *
     * @return array<string, mixed>
     */
    public function canonicalRow(): array
    {
        return [
            'id'          => $this->id,
            'timesheetId' => $this->timesheet->getId(),
            'seq'         => $this->seq,
            'action'      => $this->action->value,
            'fromStatus'  => $this->fromStatus->value,
            'toStatus'    => $this->toStatus->value,
            'actorRef'    => $this->actorRef,
            'actorRole'   => $this->actorRole->value,
            'comment'     => $this->comment,
            'entriesHash' => $this->entriesHash,
            'totals'      => $this->totals,
            'client'      => $this->client->value,
            'occurredAt'  => StorageTime::utc($this->occurredAt),
            'prevHash'    => $this->prevHash,
        ];
    }

    /** Row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The timesheet. */
    public function getTimesheet(): Timesheet
    {
        return $this->timesheet;
    }

    /** Position in the chain (1, 2, …). */
    public function getSeq(): int
    {
        return $this->seq;
    }

    /** What happened. */
    public function getAction(): TimesheetAction
    {
        return $this->action;
    }

    /** Status before. */
    public function getFromStatus(): TimesheetStatus
    {
        return $this->fromStatus;
    }

    /** Status after. */
    public function getToStatus(): TimesheetStatus
    {
        return $this->toStatus;
    }

    /** Opaque actor id (null = system). */
    public function getActorRef(): ?string
    {
        return $this->actorRef;
    }

    /** Actor role at that time. */
    public function getActorRole(): TimesheetActorRole
    {
        return $this->actorRole;
    }

    /** Comment (sanitised; shown to the owner). */
    public function getComment(): ?string
    {
        return $this->comment;
    }

    /** Entry-list hash at this transition. */
    public function getEntriesHash(): ?string
    {
        return $this->entriesHash;
    }

    /**
     * Snapshot at this transition (submit / approve).
     *
     * @return array<string, mixed>|null
     */
    public function getTotals(): ?array
    {
        return $this->totals;
    }

    /** Channel. */
    public function getClient(): AuditClient
    {
        return $this->client;
    }

    /** Server time of the transition. */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Hash of the previous row (null for the first). */
    public function getPrevHash(): ?string
    {
        return $this->prevHash;
    }

    /** Stored hash of this row. */
    public function getHash(): string
    {
        return $this->hash;
    }
}
