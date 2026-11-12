<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;

/**
 * Since 1.23.0: a time entry store that also holds imported entries ({@see TimeEntrySource::Import}), framework-free
 * (REQ-ARCH-001 rule 3): the methods of {@see ImportedTimeEntryRepositoryInterface} (1.11) without
 * `attachUser(UserInterface)`. Required by {@see ImportedTimeEntryWriter}; the Doctrine store implements it. A store
 * that implements only this interface is never asked to re-attach the user after a failed save (the user object the
 * writer was called with is used as it is).
 */
interface ImportedTimeEntryStoreInterface extends PaginatedTimeEntryRepositoryInterface
{
    /**
     * The imported entry of an external identity, or null.
     */
    public function findByExternal(string $externalSource, string $externalId): ?TimeEntry;

    /**
     * Every imported entry of a source, keyset-batched (constant memory).
     *
     * @return iterable<TimeEntry>
     */
    public function findByExternalSource(string $externalSource, int $batchSize = 500): iterable;

    /**
     * Live entries only (never imported) of one user started within the period, newest first: what overlap checks,
     * working-hours registers and correction windows must read.
     *
     * @return list<TimeEntry>
     */
    public function findLiveByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Live entries only of the users started within the period, newest first (lists with imported entries excluded).
     *
     * @param list<string> $userIds
     *
     * @return list<TimeEntry>
     */
    public function findLivePageByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, int $offset, int $limit): array;

    /**
     * @param list<string> $userIds
     */
    public function countLiveByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): int;

    /**
     * Persists the entries and flushes once.
     *
     * @param list<TimeEntry> $entries
     */
    public function saveAll(array $entries): void;

    /**
     * Deletes the imported entries of a scope by keyset batches (never loads entities); returns what was deleted.
     */
    public function removeImported(ImportedTimeEntryScope $scope, int $batchSize = 500): ImportedTimeEntryRemoval;

    /**
     * After a failed {@see self::saveAll()} (a unique violation from a concurrent run): whether the store can be read and
     * written again within the same call. False when the failure left it unusable (the Doctrine store: the entity
     * manager is still closed because no `ManagerRegistry` reset it) or when a host transaction is still open (its
     * snapshot may hide the concurrent row, and some platforms abort it): the writer then rethrows instead of retrying.
     */
    public function canRetryAfterFailedSave(): bool;
}
