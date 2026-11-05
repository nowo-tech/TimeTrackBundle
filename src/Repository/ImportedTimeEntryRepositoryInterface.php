<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.11: a time entry store that also holds imported entries ({@see TimeEntrySource::Import}).
 * Required by {@see ImportedTimeEntryWriter}; the Doctrine store implements it.
 */
interface ImportedTimeEntryRepositoryInterface extends PaginatedTimeEntryRepositoryInterface
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

    /**
     * The user as an object new entries can reference after the store was reset (the Doctrine store: the managed
     * instance, or a reference by identifier, since a reset detaches the objects the caller passed). Returns the user
     * unchanged when it is already managed or is not an entity of this store.
     */
    public function attachUser(UserInterface $user): UserInterface;
}
