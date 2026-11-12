<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Doctrine\FormerPersonSnapshotListener;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Service\TimeEntryBillableService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\Uuid;

use function hash;
use function str_starts_with;
use function strlen;
use function substr;

use const DATE_ATOM;

/**
 * Closed time entry: task snapshot, start, end, duration and source. Since 1.11 an entry may also be imported from
 * another tool ({@see TimeEntrySource::Import}): it then carries an external identity (unique pair), and is read-only.
 *
 * Since 1.15 an entry may have no user (owner rule 2026-11-06: tracked time is not lost, including when a user is
 * deleted): the imported hours of a former person who never had an account, or an entry whose user was deleted
 * (`user_id` is `ON DELETE SET NULL`). It then shows under `person_name_snapshot`; `person_ref` is the opaque identity
 * erasure and export look it up by; {@see self::isAnonymised()} entries keep their time and nothing else about the person.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_entries')]
#[ORM\Index(name: 'time_track_entries_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'time_track_entries_task_idx', columns: ['task_id'])]
#[ORM\Index(name: 'time_track_entries_started_idx', columns: ['started_at'])]
// Since 1.11: keyset purges and reports by end time (same name and columns as Beacon's own migration, so adopting it is a no-op).
#[ORM\Index(name: 'time_track_entries_ended_idx', columns: ['ended_at', 'id'])]
#[ORM\Index(name: 'idx_time_track_entries_source', columns: ['source'])]
#[ORM\UniqueConstraint(name: 'uniq_time_track_entries_external', columns: ['external_source', 'external_id'])]
// Since 1.13: period queries and lock checks per tenant scope.
#[ORM\Index(name: 'idx_time_track_entries_user_scope_started', columns: ['user_id', 'scope_key', 'started_at'])]
// Since 1.15: erasure and export of a former person.
#[ORM\Index(name: 'idx_time_track_entries_person_ref', columns: ['person_ref'])]
class TimeEntry
{
    /**
     * Title shown instead of the snapshot of an imported entry whose task the owner cannot see, when no translator is
     * available (the client API translates `reports.task_not_visible` when it has one).
     */
    public const HIDDEN_TASK_TITLE = 'Task not visible';

    /** Since 1.15: prefix of the {@see self::$personRef} of an entry that belonged to a user account. */
    public const USER_REF_PREFIX = 'user:';

    /** Since 1.15: `metadata.closed_by` of an entry whose running timer was closed because its user was being deleted. */
    public const CLOSED_BY_USER_DELETION = 'user_deletion';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** Since 1.11: the importing system and its scope, e.g. "clickup:<directory id>" (null for live entries). */
    #[ORM\Column(name: 'external_source', type: 'string', length: 64, nullable: true)]
    private ?string $externalSource = null;

    /** Since 1.11: the entry's id in the importing system (unique with the source). */
    #[ORM\Column(name: 'external_id', type: 'string', length: 128, nullable: true)]
    private ?string $externalId = null;

    /** Since 1.11: billable flag when the source tool has one. */
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $billable = null;

    /** Since 1.11: plain-text description, kept in full (no length cap). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Since 1.13: host tenant scope at write time (timesheets); null = unknown (pre-1.13 rows until back-filled, or
     * timesheets off). A null-scope entry belongs to no timesheet.
     */
    #[ORM\Column(name: 'scope_key', type: 'string', length: 64, nullable: true)]
    private ?string $scopeKey = null;

    /** Since 1.15: the person's name when the entry has no user (null = unknown or anonymised). */
    #[ORM\Column(name: 'person_name_snapshot', type: 'string', length: 255, nullable: true)]
    private ?string $personNameSnapshot = null;

    /**
     * Since 1.15: opaque identity of the person, host-given for a former person ({@see ImportedTimeEntryPerson::$ref}),
     * {@see self::USER_REF_PREFIX} + user id once the entry's user is being or was deleted. Null while a user owns the
     * entry, after a delete the bundle did not see and after anonymising.
     */
    #[ORM\Column(name: 'person_ref', type: 'string', length: 69, nullable: true)]
    private ?string $personRef = null;

    /** Since 1.15: when the person was anonymised; an anonymised entry is never written again. */
    #[ORM\Column(name: 'anonymised_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $anonymisedAt = null;

    /**
     * Creates a time entry.
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $user,
        #[ORM\Column(name: 'task_id', type: 'string', length: 36)]
        private string $taskId,
        #[ORM\Column(name: 'task_title_snapshot', type: 'string', length: 255)]
        private string $taskTitleSnapshot,
        #[ORM\Column(name: 'board_id_snapshot', type: 'string', length: 36, nullable: true)]
        private ?string $boardIdSnapshot,
        #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
        private DateTimeImmutable $startedAt,
        #[ORM\Column(name: 'ended_at', type: 'datetime_immutable')]
        private DateTimeImmutable $endedAt,
        #[ORM\Column(name: 'duration_seconds', type: 'integer')]
        private int $durationSeconds,
        #[ORM\Column(type: 'string', length: 16, enumType: TimeEntrySource::class)]
        private TimeEntrySource $source,
        /** @var array<string, mixed> */
        #[ORM\Column(type: 'json', nullable: true)]
        private ?array $metadata = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * Since 1.11: a new imported entry ({@see TimeEntrySource::Import}) from validated data; use
     * {@see ImportedTimeEntryWriter}, which validates and deduplicates.
     *
     * @internal
     */
    public static function imported(?object $user, ImportedTimeEntryData $data): self
    {
        $entry = new self($user, $data->taskId, $data->taskTitle, $data->boardId, $data->startedAt, $data->endedAt, $data->durationSeconds, TimeEntrySource::Import);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->externalSource = $data->externalSource;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->externalId = $data->externalId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->billable = $data->billable;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->description = $data->description;

        return $entry;
    }

    /**
     * Since 1.15: a new imported entry of a person with no user account; use {@see ImportedTimeEntryWriter}.
     *
     * @internal
     */
    public static function importedForPerson(ImportedTimeEntryPerson $person, ImportedTimeEntryData $data): self
    {
        $entry = self::imported(null, $data);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->personRef = $person->ref;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->personNameSnapshot = $person->name;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $entry->scopeKey = $person->scopeKey;

        return $entry;
    }

    /**
     * Since 1.15: the person of this ownerless imported entry changed their name.
     *
     * @internal used by the imported-entry writer only
     */
    public function applyPersonName(?string $name): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->personNameSnapshot = $name;
    }

    /**
     * Since 1.15: this loaded entry's user is being deleted in the current flush: the same result the foreign key and the
     * bulk snapshot give the rows, so the entity holds no reference to a removed user.
     *
     * @internal used by {@see FormerPersonSnapshotListener} only
     */
    public function detachDeletedUser(string $personRef, ?string $name): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->user = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->personRef = $personRef;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->personNameSnapshot = $name;
    }

    /**
     * Since 1.15: what anonymising does to this entity, for one loaded in the current flush (the store does the same in bulk).
     *
     * @internal used by {@see FormerPersonSnapshotListener} only
     */
    public function anonymiseInPlace(DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->user = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->personRef = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->personNameSnapshot = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskTitleSnapshot = '';
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->metadata = self::anonymisedMetadata($this->metadata);
        if ($this->externalSource !== null && $this->externalId !== null) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->externalId = self::anonymisedExternalId($this->externalSource, $this->externalId);
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->anonymisedAt = $at;
    }

    /**
     * Since 1.15: the metadata an anonymised entry keeps: only the {@see self::CLOSED_BY_USER_DELETION} mark, which is not
     * personal data and explains the end time.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>|null
     */
    public static function anonymisedMetadata(?array $metadata): ?array
    {
        return (($metadata ?? [])['closed_by'] ?? null) === self::CLOSED_BY_USER_DELETION ? ['closed_by' => self::CLOSED_BY_USER_DELETION] : null;
    }

    /**
     * Since 1.15: the external id an anonymised entry keeps: a hash, so a re-sync still finds it and writes nothing, and the
     * remote entry is not named. A pseudonym: whoever holds the remote id can recompute it, and a small id space can be enumerated.
     */
    public static function anonymisedExternalId(string $externalSource, string $externalId): string
    {
        return 'anon:' . hash('sha256', $externalSource . "\0" . $externalId);
    }

    /**
     * Since 1.15: a host user now stands for the former person of this ownerless entry; the snapshot goes.
     *
     * @internal used by the imported-entry writer only
     */
    public function claimFor(object $user): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->user = $user;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->personRef = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->personNameSnapshot = null;
    }

    /**
     * Since 1.11: applies newer imported data (same external identity); true when a field changed.
     *
     * @internal used by the imported-entry writer only
     */
    public function applyImport(ImportedTimeEntryData $data): bool
    {
        if (!$this->differsFromImport($data)) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskId = $data->taskId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->taskTitleSnapshot = $data->taskTitle;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->boardIdSnapshot = $data->boardId;
        if ($this->startedAt->getTimestamp() !== $data->startedAt->getTimestamp()) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->startedAt = $data->startedAt;
        }
        if ($this->endedAt->getTimestamp() !== $data->endedAt->getTimestamp()) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->endedAt = $data->endedAt;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->durationSeconds = $data->durationSeconds;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->billable = $data->billable;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->description = $data->description;

        return true;
    }

    /**
     * Since 1.13: whether {@see self::applyImport()} would change a field (checked before the period lock).
     *
     * @internal used by the imported-entry writer only
     */
    public function differsFromImport(ImportedTimeEntryData $data): bool
    {
        $before = [$this->taskId, $this->taskTitleSnapshot, $this->boardIdSnapshot, $this->startedAt->getTimestamp(), $this->endedAt->getTimestamp(), $this->durationSeconds, $this->billable, $this->description];
        $after  = [$data->taskId, $data->taskTitle, $data->boardId, $data->startedAt->getTimestamp(), $data->endedAt->getTimestamp(), $data->durationSeconds, $data->billable, $data->description];

        return $before !== $after;
    }

    /**
     * Entry id (UUID).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Entry owner. Since 1.15 null when the entry has no user: a former person's imported hours, or the user was deleted.
     */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /**
     * Since 1.18: id of the entry owner as a string (null for an ownerless entry), without loading the user.
     */
    public function getUserId(): ?string
    {
        return UserIdResolver::idOf($this->user);
    }

    /**
     * Since 1.15: whether the entry has no user. It shows under {@see self::getPersonName()}.
     */
    public function isOwnerless(): bool
    {
        return $this->user === null;
    }

    /**
     * Since 1.15: the name of the person of an ownerless entry (null = show a generic "former person").
     */
    public function getPersonName(): ?string
    {
        return $this->user === null ? $this->personNameSnapshot : null;
    }

    /**
     * Since 1.15: opaque identity of the person for erasure and export; hosts never show it.
     */
    public function getPersonRef(): ?string
    {
        return $this->personRef;
    }

    /**
     * Since 1.15: id of the deleted user this ownerless entry belonged to, while it is known (null otherwise): timesheets
     * keep the same id as `owner_ref`, so the period lock still holds for the entry.
     */
    public function getFormerUserId(): ?string
    {
        return $this->user === null ? self::formerUserIdOf($this->personRef) : null;
    }

    /**
     * Since 1.15: the user id inside a {@see self::getPersonRef()} value (null for a host-given person reference).
     */
    public static function formerUserIdOf(?string $personRef): ?string
    {
        return $personRef !== null && str_starts_with($personRef, self::USER_REF_PREFIX) ? substr($personRef, strlen(self::USER_REF_PREFIX)) : null;
    }

    /**
     * Since 1.15: whether the entry comes from a timer that was still running when its user was deleted, so its end is the
     * deletion time and not a stop by the person. Anonymising keeps this mark.
     */
    public function isClosedByUserDeletion(): bool
    {
        return (($this->metadata ?? [])['closed_by'] ?? null) === self::CLOSED_BY_USER_DELETION;
    }

    /**
     * Since 1.15: whether the person was anonymised (no name, reference, free text or task title; the external id is
     * hashed; the time stays). Pseudonymised rather than anonymous in the GDPR sense unless the host has assessed it: task
     * id, board, tenant and exact times stay, and in a small team they can single someone out.
     */
    public function isAnonymised(): bool
    {
        return $this->anonymisedAt instanceof DateTimeImmutable;
    }

    /**
     * Since 1.15: when the person was anonymised.
     */
    public function getAnonymisedAt(): ?DateTimeImmutable
    {
        return $this->anonymisedAt;
    }

    /**
     * Tracked task id.
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * Task title when the timer started.
     */
    public function getTaskTitleSnapshot(): string
    {
        return $this->taskTitleSnapshot;
    }

    /**
     * Board id when the timer started.
     */
    public function getBoardIdSnapshot(): ?string
    {
        return $this->boardIdSnapshot;
    }

    /**
     * Start time.
     */
    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * End time.
     */
    public function getEndedAt(): DateTimeImmutable
    {
        return $this->endedAt;
    }

    /**
     * Duration in seconds.
     */
    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    /**
     * Client that recorded the entry.
     */
    public function getSource(): TimeEntrySource
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Since 1.11: the importing system and scope (null for live entries).
     */
    public function getExternalSource(): ?string
    {
        return $this->externalSource;
    }

    /**
     * Since 1.11: the entry's id in the importing system (null for live entries).
     */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * Since 1.11: billable flag (null = unknown).
     */
    public function getBillable(): ?bool
    {
        return $this->billable;
    }

    /**
     * Since 1.21: sets the billable flag (null = unknown); true when it changed. No checks here: use
     * {@see TimeEntryBillableService}, which authorises, honours the period lock and audits.
     *
     * @internal used by the bundle's services only (a new entry's default, the billable service)
     */
    public function markBillable(?bool $billable): bool
    {
        if ($this->billable === $billable) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->billable = $billable;

        return true;
    }

    /**
     * Since 1.11: plain-text description (null = none).
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Since 1.11: imported entries are read-only (they can only be deleted).
     */
    public function isReadOnly(): bool
    {
        return $this->source->isReadOnly();
    }

    /**
     * Since 1.13: tenant scope of the entry (null = unknown, belongs to no timesheet).
     */
    public function getScopeKey(): ?string
    {
        return $this->scopeKey;
    }

    /**
     * Since 1.13: sets the tenant scope (the timer service and the import writer at write time, the back-fill command
     * afterwards). At most 64 characters.
     *
     * @internal
     */
    public function assignScopeKey(?string $scopeKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->scopeKey = $scopeKey;

        return $this;
    }

    /**
     * Creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Client API shape. Since 1.11 it also has `billable`, `description`, `readOnly` and `taskVisible`; with
     * `$taskVisible` false the title is `$hiddenTitle` (the translated `reports.task_not_visible` in the client API,
     * {@see self::HIDDEN_TASK_TITLE} by default): imported entries of a task the owner cannot see. Since 1.12 the
     * `description` of such an entry is null too (it is copied from the source and may repeat task content). The
     * external identity is never exposed. Since 1.15 an ownerless entry also has `person` (`name`, `anonymised`; the key
     * is absent for every entry with a user, and the person reference is never exposed).
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $taskVisible = true, string $hiddenTitle = self::HIDDEN_TASK_TITLE): array
    {
        $array = [
            'id'              => $this->id,
            'taskId'          => $this->taskId,
            'taskTitle'       => $taskVisible ? $this->taskTitleSnapshot : $hiddenTitle,
            'boardId'         => $this->boardIdSnapshot,
            'startedAt'       => $this->startedAt->format(DATE_ATOM),
            'endedAt'         => $this->endedAt->format(DATE_ATOM),
            'durationSeconds' => $this->durationSeconds,
            'source'          => $this->source->value,
            'metadata'        => $this->metadata,
            'billable'        => $this->billable,
            'description'     => $taskVisible ? $this->description : null,
            'readOnly'        => $this->isReadOnly(),
            'taskVisible'     => $taskVisible,
            // Since 1.13: tenant scope (null = unassigned).
            'scopeKey' => $this->scopeKey,
        ];
        if ($this->user === null) {
            $array['person'] = ['name' => $this->personNameSnapshot, 'anonymised' => $this->anonymisedAt instanceof DateTimeImmutable];
        }

        return $array;
    }
}
