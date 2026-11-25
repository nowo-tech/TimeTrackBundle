<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\PersonalData;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarPersonalDataExporter;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Doctrine\OwnedRows;
use Nowo\TimeTrackBundle\Doctrine\OwnerIdColumn;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Entity\MonitoringAcknowledgementRecord;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Entry\EntryMetadataRules;
use Nowo\TimeTrackBundle\Exception\PersonalDataErasureRefusedException;
use Nowo\TimeTrackBundle\Query\ClientAccessQueryInterface;
use Nowo\TimeTrackBundle\Query\ClientSessionView;
use Nowo\TimeTrackBundle\Query\DeviceSignInView;
use Nowo\TimeTrackBundle\Query\RunningTimerQueryInterface;
use Nowo\TimeTrackBundle\Query\RunningTimerView;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Repository\FormerPersonTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Subject\AllocationLabeler;
use Nowo\TimeTrackBundle\Subject\AllocationLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SortDirection;

use function array_column;
use function array_map;
use function count;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;

use const DATE_ATOM;

/**
 * Since 1.35.0 (DDD-host checklist B7): the Doctrine implementation of {@see TimeTrackPersonalDataInterface} on the
 * bundle's entity manager (the private one with `database.private_entity_manager: true`). Reads select scalars (no
 * entity is hydrated); writes are bulk statements in one transaction of the bundle's runner (no entity manager is
 * flushed, cleared or reset; rows this manager had loaded are detached). Only ids are bound, never a host entity.
 *
 * Concurrency (REQ-RUNTIME-003): the free-text pass locks the user's entries by id, in id order, batch by batch, so a
 * concurrent note change waits instead of being lost; deletes are single statements by owner; a lock conflict surfaces as
 * `TransientStorageFailure` with nothing committed, and a replay is safe (the erasure is idempotent).
 *
 * Stateless (FrankenPHP worker mode, kernel reset false).
 *
 * @internal Hosts depend on the interface
 */
final readonly class DoctrineTimeTrackPersonalData implements TimeTrackPersonalDataInterface
{
    /** Rows read per round. */
    public const BATCH = 500;

    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager (private or `database.entity_manager`)
     * @param TimeTrackTransactionRunner $runner The bundle's transaction runner
     * @param TimeEntryRepositoryInterface $entryStore The entry store ({@see FormerPersonTimeEntryRepositoryInterface} for the erasure)
     * @param CalendarPersonalDataExporter|null $calendars The calendar export (null when calendars are not wired)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TimeTrackTransactionRunner $runner,
        private TimeEntryQueryInterface $entries,
        private RunningTimerQueryInterface $timers,
        private ClientAccessQueryInterface $clientAccess,
        private TimeEntryRepositoryInterface $entryStore,
        private FormerPersonTimeEntries $formerPeople,
        private ?CalendarPersonalDataExporter $calendars = null,
        private ?ClockInterface $clock = null,
        private LoggerInterface $logger = new NullLogger(),
        // Since 2.1.0 (Beacon spec 256, Q-TS-17): subject and activity type labels, resolved with the data subject as actor.
        private ?AllocationLabeler $allocations = null,
        private string $userClass = '',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function export(string $userId): array
    {
        $this->assertUsable($userId);
        $timer   = $this->timers->runningOf($userId);
        $records = $this->entryRecords($userId);
        // Since 2.1.0 (Q-TS-17): ids and keys always; labels with the data subject as actor, masked when not readable.
        $items = array_map(static fn (TimeEntryRecord $record): array => [$record->entry->scopeKey, $record->entry->subject, $record->entry->activityTypeKey], $records);
        if ($timer instanceof RunningTimerView) {
            $items[] = [$timer->timer->scopeKey, $timer->timer->subject, $timer->timer->activityTypeKey];
        }
        $labels = $this->allocations?->label(TimeTrackUserRef::ofId($this->userClass, $userId), $items) ?? AllocationLabels::none();

        return [
            'entries'             => array_map(fn (TimeEntryRecord $record): array => $this->entryRow($record, $labels), $records),
            'formerPersonEntries' => $this->formerPeople->isAvailable() ? $this->formerPeople->export(TimeEntry::USER_REF_PREFIX . $userId) : [],
            'runningTimer'        => $timer instanceof RunningTimerView ? [
                'taskId'          => $timer->timer->taskId,
                'taskTitle'       => $timer->timer->taskTitle,
                'boardId'         => $timer->timer->boardId,
                'startedAt'       => $timer->timer->startedAt->format(DATE_ATOM),
                'lastHeartbeatAt' => $timer->lastHeartbeatAt->format(DATE_ATOM),
                'clientType'      => $timer->timer->clientType->value,
                'channel'         => $timer->timer->channel,
                'note'            => $timer->timer->note,
            ] + ($timer->timer->withoutTask ? ['withoutTask' => true, 'description' => $timer->timer->description, 'scopeKey' => $timer->timer->scopeKey] : []) + $this->allocationRow($timer->timer->scopeKey, $timer->timer->subject, $timer->timer->activityTypeKey, $labels) : null,
            'clientSessions' => array_map(static fn (ClientSessionView $session): array => [
                'clientType' => $session->clientType->value,
                'scopes'     => $session->scopes,
                'createdAt'  => $session->createdAt->format(DATE_ATOM),
                'lastUsedAt' => $session->lastUsedAt?->format(DATE_ATOM),
                'expiresAt'  => $session->expiresAt->format(DATE_ATOM),
                // Since 2.2.0: the scope family and the name the device gave.
                'scopeFamily' => $session->scopeFamily,
                'clientName'  => $session->clientName,
            ], $this->clientAccess->sessionsOf($userId, true)),
            'deviceSignIns' => array_map(static fn (DeviceSignInView $device): array => [
                'clientType'   => $device->clientType->value,
                'clientName'   => $device->clientName,
                'status'       => $device->status->value,
                'scopes'       => $device->scopes,
                'ipPrefix'     => $device->ipPrefix,
                'createdAt'    => $device->createdAt->format(DATE_ATOM),
                'expiresAt'    => $device->expiresAt->format(DATE_ATOM),
                'lastPolledAt' => $device->lastPolledAt?->format(DATE_ATOM),
                'scopeFamily'  => $device->scopeFamily,
            ], $this->clientAccess->deviceSignInsOf($userId)),
            'monitoringAcknowledgements' => $this->ownedRows(MonitoringAcknowledgementRecord::class, $userId, ['policyVersion', 'clientType', 'noticeSha256', 'acknowledgedAt']),
            'monitoringUploads'          => $this->ownedRows(MonitoringUploadIntent::class, $userId, ['entryId', 'slot', 'takenAt', 'status', 'policyVersion', 'blurred', 'mime', 'bytes', 'width', 'height', 'screenshotId', 'createdAt']),
            'timesheets'                 => $this->ownedRows(Timesheet::class, $userId, ['id', 'scopeKey', 'periodKind', 'periodStart', 'periodEnd', 'timezone', 'status', 'lockedAt', 'lockReason', 'submittedAt', 'decidedAt', 'decisionComment', 'totals', 'createdAt', 'updatedAt']),
            // Since 1.35.0 (audit YI L2, art. 15): the balance movements and the audit trail of the user's own timesheets.
            'balanceLedgerLines' => $this->ownedRows(BalanceLedgerLine::class, $userId, ['id', 'scopeKey', 'effectiveDate', 'kind', 'seconds', 'reason', 'reversesId', 'createdAt']),
            'timesheetEvents'    => $this->ownedRows(TimesheetEvent::class, $userId, ['seq', 'action', 'fromStatus', 'toStatus', 'actorRole', 'client', 'comment', 'occurredAt'], true),
        ] + $this->calendarSections($userId);
    }

    /**
     * {@inheritDoc}
     */
    public function erase(string $userId, TimeEntryErasure $entries = TimeEntryErasure::Anonymise): TimeTrackErasureResult
    {
        $this->assertUsable($userId);
        $store = $this->entryStore instanceof FormerPersonTimeEntryRepositoryInterface
            ? $this->entryStore
            : throw new LogicException('The TimeTrack erasure requires an entry store implementing ' . FormerPersonTimeEntryRepositoryInterface::class . '.');
        $now = $this->clock?->now() ?? new DateTimeImmutable();

        /** @var TimeTrackErasureResult $result */
        $result = $this->runner->runStatements(fn (): TimeTrackErasureResult => $this->eraseInTransaction($userId, $entries, $store, $now));

        $this->logger->info('TimeTrack personal data erased.', [
            'bundle'                     => 'time_track',
            'action'                     => 'personal_data_erase',
            'entries'                    => $entries->value,
            'clientSessions'             => $result->clientSessions,
            'deviceSignIns'              => $result->deviceSignIns,
            'monitoringUploads'          => $result->monitoringUploads,
            'monitoringAcknowledgements' => $result->monitoringAcknowledgements,
            'entriesAnonymised'          => $result->entriesAnonymised,
            'entriesCleared'             => $result->entriesCleared,
        ]);
        if ($result->calendarConnectionsKept > 0) {
            // Owner decision 4: calendar grants are revoked by the host (provider tokens are revoked over HTTP).
            $this->logger->warning('TimeTrack personal data erased, but {count} calendar connection(s) of the user remain: call CalendarConnectionService::revokeAllFor() to revoke them.', [
                'bundle' => 'time_track',
                'action' => 'personal_data_erase',
                'count'  => $result->calendarConnectionsKept,
            ]);
        }

        return $result;
    }

    /**
     * The erasure's write unit (inside the runner's transaction).
     */
    private function eraseInTransaction(string $userId, TimeEntryErasure $entries, FormerPersonTimeEntryRepositoryInterface $store, DateTimeImmutable $now): TimeTrackErasureResult
    {
        // Owner decision 2 (confirmed 2026-11-21): refuse while a timer runs, so no tracked time is ever lost.
        // Alternative: delete the user's ActiveTimer row here (deleteOwned(ActiveTimer::class, ...)), as Beacon does today.
        // Read inside the transaction: a timer stopped meanwhile does not block, a running one writes nothing.
        if ($this->timers->runningOf($userId) instanceof RunningTimerView) {
            throw new PersonalDataErasureRefusedException(PersonalDataErasureRefusedException::TIMER_RUNNING);
        }
        $personRef = TimeEntry::USER_REF_PREFIX . $userId;

        return new TimeTrackErasureResult(
            $this->deleteOwned(ClientToken::class, $userId),
            $this->deleteOwned(DeviceAuthorization::class, $userId),
            $this->deleteOwned(MonitoringUploadIntent::class, $userId),
            // Owner decision 3 (confirmed 2026-11-21): delete the monitoring acknowledgements, as Beacon does.
            // Alternative: keep them as evidence of the notice (count 0 here; export() still lists them).
            $this->deleteOwned(MonitoringAcknowledgementRecord::class, $userId),
            // Owner decisions 1 and 5 (confirmed 2026-11-21): TimeEntryErasure::Anonymise is the default (interface);
            // FreeText clears text in every period, locked or submitted ones included (the timesheet hash excludes
            // text). Alternatives: FreeText as the default; FreeText skipping entries of locked/submitted periods.
            ($entries === TimeEntryErasure::Anonymise && $this->stores(TimeEntry::class, $userId) ? $store->anonymiseOwner($userId, $now) : 0) + $store->anonymisePerson($personRef, $now),
            $entries === TimeEntryErasure::FreeText ? $this->clearFreeText($userId) : 0,
            // Owner decision 4 (kept as built, 2026-11-21): timesheets and the audit trail are kept (GDPR art. 17.3.b);
            // calendar connections are only counted, CalendarConnectionService::revokeAllFor() revokes them.
            // Alternative: revoke the calendar connections here too.
            count(OwnedRows::ids($this->entityManager, CalendarConnection::class, $userId)),
        );
    }

    /**
     * Whether the owner column of the entity can hold the id (an id it cannot hold owns no row and never reaches the SQL).
     *
     * @param class-string $class
     */
    private function stores(string $class, string $userId): bool
    {
        return OwnerIdColumn::accepts($this->entityManager, $class, $userId);
    }

    /**
     * The calendar sections of the export (none when calendars are not wired).
     *
     * @return array<string, mixed>
     */
    private function calendarSections(string $userId): array
    {
        // No calendar exporter (direct construction): no calendar section; an id the owner column cannot hold: empty ones.
        $empty = $this->calendars instanceof CalendarPersonalDataExporter ? ['calendarConnections' => [], 'calendarRules' => [], 'calendarSuggestions' => []] : [];

        return $this->calendars instanceof CalendarPersonalDataExporter && $this->stores(CalendarConnection::class, $userId) ? $this->calendars->export($userId) : $empty;
    }

    /**
     * @throws PersonalDataErasureRefusedException When the id could reach a look-alike's rows
     */
    private function assertUsable(string $userId): void
    {
        if ($userId === '' || !DoctrineUserReferenceResolver::isExactId($userId)) {
            throw new PersonalDataErasureRefusedException(PersonalDataErasureRefusedException::INVALID_USER_ID);
        }
    }

    /**
     * Every owned entry of the user, by keyset.
     *
     * @return list<TimeEntryRecord>
     */
    private function entryRecords(string $userId): array
    {
        $criteria = TimeEntryCriteria::ofOwner($userId);
        $records  = [];
        $after    = '';
        do {
            $page = $this->entries->listAfter($criteria, $after, self::BATCH);
            foreach ($page as $record) {
                $after     = $record->entry->id;
                $records[] = $record;
            }
        } while (count($page) === self::BATCH);

        return $records;
    }

    /**
     * Since 2.1.0: the subject and activity type keys of an exported row (ids and key always, labels masked when the data
     * subject may not read them; Q-TS-17). Nothing for a row with neither.
     *
     * @return array<string, string|null>
     */
    private function allocationRow(?string $scopeKey, ?TimeSubjectRef $subject, ?string $activityTypeKey, AllocationLabels $labels): array
    {
        if (!$subject instanceof TimeSubjectRef && $activityTypeKey === null) {
            return [];
        }

        return [
            'subjectType'       => $subject?->type,
            'subjectId'         => $subject?->id,
            'subjectLabel'      => $subject instanceof TimeSubjectRef ? ($labels->subjectLabel($scopeKey, $subject)->label ?? $labels->hiddenLabel()) : null,
            'activityTypeKey'   => $activityTypeKey,
            'activityTypeLabel' => $activityTypeKey === null ? null : $labels->activityTypeLabel($scopeKey, $activityTypeKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(TimeEntryRecord $record, AllocationLabels $labels): array
    {
        $entry = $record->entry;

        return [
            'id'              => $entry->id,
            'taskId'          => $entry->taskId,
            'taskTitle'       => $entry->taskTitle,
            'boardId'         => $entry->boardId,
            'scopeKey'        => $entry->scopeKey,
            'startedAt'       => $entry->startedAt->format(DATE_ATOM),
            'endedAt'         => $entry->endedAt->format(DATE_ATOM),
            'durationSeconds' => $entry->durationSeconds,
            'source'          => $entry->source->value,
            'externalSource'  => $entry->externalSource,
            'billable'        => $record->billable,
            'description'     => $record->description,
            'note'            => $record->note,
            'metadata'        => $record->metadata,
            'channel'         => $entry->channel,
            'serviceAccount'  => $entry->serviceAccount,
            'createdAt'       => $record->createdAt->format(DATE_ATOM),
        ] + ($entry->withoutTask ? ['withoutTask' => true] : []) + $this->allocationRow($entry->scopeKey, $entry->subject, $entry->activityTypeKey, $labels);
    }

    /**
     * The user's rows of a bundle entity as JSON-ready arrays, by keyset on the id, owners checked byte for byte.
     *
     * @param class-string $class
     * @param list<string> $fields
     * @param bool $viaTimesheet The rows belong to the user's timesheets (the owner is the timesheet's)
     *
     * @return list<array<string, mixed>>
     */
    private function ownedRows(string $class, string $userId, array $fields, bool $viaTimesheet = false): array
    {
        if (!$this->stores($viaTimesheet ? Timesheet::class : $class, $userId)) {
            return [];
        }
        $owner  = $viaTimesheet ? 'IDENTITY(t.user)' : 'IDENTITY(x.user)';
        $select = ['x.id AS rowId', $owner . ' AS ownerId'];
        if ($viaTimesheet) {
            $select[] = 'IDENTITY(x.timesheet) AS timesheetId';
        }
        foreach ($fields as $field) {
            $select[] = 'x.' . $field . ' AS ' . $field;
        }
        $rows  = [];
        $after = '';
        do {
            $query = $this->entityManager->createQueryBuilder()->select(...$select)->from($class, 'x');
            $batch = ($viaTimesheet ? $query->innerJoin('x.timesheet', 't') : $query)
                ->where($owner . ' = :owner')
                ->andWhere('x.id > :after')
                ->setParameter('owner', $userId)
                ->setParameter('after', $after)
                ->orderBy('x.id', SortDirection::Ascending)
                ->setMaxResults(self::BATCH)
                ->getQuery()
                ->getArrayResult();
            foreach ($batch as $row) {
                $after = is_scalar($row['rowId']) ? (string) $row['rowId'] : $after;
                // Only a case-folding collation (MySQL / MariaDB suites) returns a look-alike row here.
                if (!is_scalar($row['ownerId']) || (string) $row['ownerId'] !== $userId) {
                    continue; // @codeCoverageIgnore
                }
                $out = $viaTimesheet ? ['timesheetId' => $row['timesheetId']] : [];
                foreach ($fields as $field) {
                    $out[$field] = $this->exported($row[$field] ?? null);
                }
                $rows[] = $out;
            }
        } while (count($batch) === self::BATCH);

        return $rows;
    }

    private function exported(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum        => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default                             => $value,
        };
    }

    /**
     * Deletes the user's rows of a bundle entity: read and locked by owner, checked byte for byte in PHP, deleted by id
     * (audit YI L1: a case-insensitive owner column never reaches a look-alike's rows); then detaches the deleted rows this
     * manager had loaded (a later flush must not see them).
     *
     * @param class-string $class
     */
    private function deleteOwned(string $class, string $userId): int
    {
        $ids        = OwnedRows::ids($this->entityManager, $class, $userId, true);
        $deleted    = OwnedRows::delete($this->entityManager, $class, $ids);
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($ids as $id) {
            $loaded = $unitOfWork->tryGetById($id, $class);
            if ($loaded !== false) {
                $this->entityManager->detach($loaded);
            }
        }

        return $deleted;
    }

    /**
     * {@see TimeEntryErasure::FreeText}: clears the description, `metadata.note` and (since 1.40.1) every other string value
     * of the metadata except the structural keys ({@see EntryMetadataRules::STRUCTURAL_KEYS}) of the entries the user still owns,
     * locked by id in id order batch by batch (a concurrent note change waits). Anonymised entries are never written.
     */
    private function clearFreeText(string $userId): int
    {
        if (!$this->stores(TimeEntry::class, $userId)) {
            return 0;
        }
        $cleared = 0;
        $after   = '';
        do {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('e.id AS id', 'IDENTITY(e.user) AS ownerId', 'e.description AS description', 'e.metadata AS metadata')
                ->from(TimeEntry::class, 'e')
                ->where('IDENTITY(e.user) = :owner')
                ->andWhere('e.anonymisedAt IS NULL')
                ->andWhere('e.id > :after')
                ->setParameter('owner', $userId)
                ->setParameter('after', $after)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(self::BATCH)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getArrayResult();
            foreach ($rows as $row) {
                $after    = is_scalar($row['id']) ? (string) $row['id'] : $after;
                $stored   = is_array($row['metadata']) ? $row['metadata'] : null;
                $metadata = $this->withoutFreeText($stored);
                if (!is_scalar($row['ownerId']) || (string) $row['ownerId'] !== $userId || ($row['description'] === null && $metadata === $stored)) {
                    continue;
                }
                $cleared += (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
                    ->update(TimeEntry::class, 'e')
                    ->set('e.description', 'NULL')
                    ->set('e.metadata', ':metadata')
                    ->where('e.id = :id')
                    ->andWhere('IDENTITY(e.user) = :owner')
                    ->andWhere('e.anonymisedAt IS NULL')
                    ->setParameter('metadata', $metadata === [] ? null : $metadata, Types::JSON)
                    ->setParameter('id', $row['id'])
                    ->setParameter('owner', $userId)
                    ->getQuery(), $this->entityManager);
            }
            $this->detachEntries(array_column($rows, 'id'));
        } while (count($rows) === self::BATCH);

        return $cleared;
    }

    /**
     * Since 1.40.1 (audit ZAN-4): the metadata without personal free text: the note and every other string value except
     * the bundle's structural keys ({@see EntryMetadataRules::STRUCTURAL_KEYS}); numbers, booleans and nested values stay.
     *
     * @param array<array-key, mixed>|null $metadata
     *
     * @return array<array-key, mixed>|null
     */
    private function withoutFreeText(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }
        foreach ($metadata as $key => $value) {
            if (is_string($value) && !in_array($key, EntryMetadataRules::STRUCTURAL_KEYS, true)) {
                unset($metadata[$key]);
            }
        }

        return $metadata;
    }

    /**
     * @param list<mixed> $ids
     */
    private function detachEntries(array $ids): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[TimeEntry::class] ?? [] as $loaded) {
            if ($loaded instanceof TimeEntry && in_array($loaded->getId(), $ids, true)) {
                $this->entityManager->detach($loaded);
            }
        }
    }
}
