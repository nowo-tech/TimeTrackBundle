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
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Entity\MonitoringAcknowledgementRecord;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
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
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Nowo\TimeTrackBundle\UserRef\ExactUserIds;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SortDirection;

use function array_column;
use function array_key_exists;
use function array_map;
use function count;
use function in_array;
use function is_array;
use function is_scalar;

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
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function export(string $userId): array
    {
        $this->assertUsable($userId);
        $timer = $this->timers->runningOf($userId);

        return [
            'entries'             => $this->entryRows($userId),
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
            ] : null,
            'clientSessions' => array_map(static fn (ClientSessionView $session): array => [
                'clientType' => $session->clientType->value,
                'scopes'     => $session->scopes,
                'createdAt'  => $session->createdAt->format(DATE_ATOM),
                'lastUsedAt' => $session->lastUsedAt?->format(DATE_ATOM),
                'expiresAt'  => $session->expiresAt->format(DATE_ATOM),
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
            ], $this->clientAccess->deviceSignInsOf($userId)),
            'monitoringAcknowledgements' => $this->ownedRows(MonitoringAcknowledgementRecord::class, $userId, ['policyVersion', 'clientType', 'noticeSha256', 'acknowledgedAt']),
            'monitoringUploads'          => $this->ownedRows(MonitoringUploadIntent::class, $userId, ['entryId', 'slot', 'takenAt', 'status', 'policyVersion', 'blurred', 'mime', 'bytes', 'width', 'height', 'screenshotId', 'createdAt']),
            'timesheets'                 => $this->ownedRows(Timesheet::class, $userId, ['id', 'scopeKey', 'periodKind', 'periodStart', 'periodEnd', 'timezone', 'status', 'lockedAt', 'lockReason', 'submittedAt', 'decidedAt', 'decisionComment', 'totals', 'createdAt', 'updatedAt']),
        ] + ($this->calendars?->export($userId) ?? []);
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

        return $result;
    }

    /**
     * The erasure's write unit (inside the runner's transaction).
     */
    private function eraseInTransaction(string $userId, TimeEntryErasure $entries, FormerPersonTimeEntryRepositoryInterface $store, DateTimeImmutable $now): TimeTrackErasureResult
    {
        // Owner decision 2 (pending; recommended): refuse while a timer runs, so no tracked time is ever lost.
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
            // Owner decision 3 (pending; recommended): delete the monitoring acknowledgements, as Beacon does.
            // Alternative: keep them as evidence of the notice (count 0 here; export() still lists them).
            $this->deleteOwned(MonitoringAcknowledgementRecord::class, $userId),
            // Owner decisions 1 and 5 (pending; recommended): TimeEntryErasure::Anonymise is the default (interface);
            // FreeText clears text in every period, locked or submitted ones included (the timesheet hash excludes
            // text). Alternatives: FreeText as the default; FreeText skipping entries of locked/submitted periods.
            ($entries === TimeEntryErasure::Anonymise ? $store->anonymiseOwner($userId, $now) : 0) + $store->anonymisePerson($personRef, $now),
            $entries === TimeEntryErasure::FreeText ? $this->clearFreeText($userId) : 0,
            // Owner decision 4 (pending; recommended): timesheets and the audit trail are kept (GDPR art. 17.3.b);
            // calendar connections are only counted, CalendarConnectionService::revokeAllFor() revokes them.
            // Alternative: revoke the calendar connections here too.
            count($this->ownedIds(CalendarConnection::class, $userId)),
        );
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
     * @return list<array<string, mixed>>
     */
    private function entryRows(string $userId): array
    {
        $criteria = TimeEntryCriteria::ofOwner($userId);
        $rows     = [];
        $after    = '';
        do {
            $page = $this->entries->listAfter($criteria, $after, self::BATCH);
            foreach ($page as $record) {
                $after  = $record->entry->id;
                $rows[] = $this->entryRow($record);
            }
        } while (count($page) === self::BATCH);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(TimeEntryRecord $record): array
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
        ];
    }

    /**
     * The user's rows of a bundle entity as JSON-ready arrays, by keyset on the id, owners checked byte for byte.
     *
     * @param class-string $class
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>>
     */
    private function ownedRows(string $class, string $userId, array $fields): array
    {
        $select = ['x.id AS rowId', 'IDENTITY(x.user) AS ownerId'];
        foreach ($fields as $field) {
            $select[] = 'x.' . $field . ' AS ' . $field;
        }
        $rows  = [];
        $after = '';
        do {
            $batch = $this->entityManager->createQueryBuilder()
                ->select(...$select)
                ->from($class, 'x')
                ->where('IDENTITY(x.user) = :owner')
                ->andWhere('x.id > :after')
                ->setParameter('owner', $userId)
                ->setParameter('after', $after)
                ->orderBy('x.id', SortDirection::Ascending)
                ->setMaxResults(self::BATCH)
                ->getQuery()
                ->getArrayResult();
            foreach ($batch as $row) {
                $after = is_scalar($row['rowId']) ? (string) $row['rowId'] : $after;
                if (!is_scalar($row['ownerId']) || (string) $row['ownerId'] !== $userId) {
                    continue;
                }
                $out = [];
                foreach ($fields as $field) {
                    $out[$field] = $this->exported($row[$field] ?? null);
                }
                $rows[] = $out;
            }
        } while (count($batch) === self::BATCH);

        return $rows;
    }

    /**
     * The ids of the user's rows of a bundle entity (owners checked byte for byte).
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private function ownedIds(string $class, string $userId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('x.id AS id', 'IDENTITY(x.user) AS ownerId')
            ->from($class, 'x')
            ->where('IDENTITY(x.user) = :owner')
            ->setParameter('owner', $userId)
            ->getQuery()
            ->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            if (is_scalar($row['ownerId']) && (string) $row['ownerId'] === $userId && is_scalar($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }

        return $ids;
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
     * Deletes the user's rows of a bundle entity in one statement by owner, then detaches the rows of that user this
     * manager had loaded (a later flush must not see them).
     *
     * @param class-string $class
     */
    private function deleteOwned(string $class, string $userId): int
    {
        $deleted = (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->delete($class, 'x')
            ->where('IDENTITY(x.user) = :owner')
            ->setParameter('owner', $userId)
            ->getQuery(), $this->entityManager);
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[$class] ?? [] as $loaded) {
            if (ExactUserIds::ownerId($loaded) === $userId) {
                $this->entityManager->detach($loaded);
            }
        }

        return $deleted;
    }

    /**
     * {@see TimeEntryErasure::FreeText}: clears the description and `metadata.note` of the entries the user still owns,
     * locked by id in id order batch by batch (a concurrent note change waits). Anonymised entries are never written.
     */
    private function clearFreeText(string $userId): int
    {
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
                $metadata = is_array($row['metadata']) ? $row['metadata'] : null;
                $hasNote  = $metadata !== null && array_key_exists('note', $metadata);
                if (!is_scalar($row['ownerId']) || (string) $row['ownerId'] !== $userId || ($row['description'] === null && !$hasNote)) {
                    continue;
                }
                unset($metadata['note']);
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
