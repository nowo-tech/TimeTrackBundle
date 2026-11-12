<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryStoreInterface;

/**
 * Delegating store whose next {@see self::findByExternal()} misses once: as when a concurrent import inserted that
 * identity after this run read it, so the flush hits the unique `(external_source, external_id)` index. Since 1.23.0: it
 * implements only the framework-free {@see ImportedTimeEntryStoreInterface} (no `attachUser()`).
 */
final class RacingImportedTimeEntryStore implements ImportedTimeEntryStoreInterface
{
    private int $staleReads = 0;

    public int $saveAllCalls = 0;

    public function __construct(
        private readonly ImportedTimeEntryRepositoryInterface $inner,
    ) {
    }

    public function staleReads(int $count): void
    {
        $this->staleReads = $count;
    }

    public function findByExternal(string $externalSource, string $externalId): ?TimeEntry
    {
        if ($this->staleReads > 0) {
            --$this->staleReads;

            return null;
        }

        return $this->inner->findByExternal($externalSource, $externalId);
    }

    public function findByExternalSource(string $externalSource, int $batchSize = 500): iterable
    {
        return $this->inner->findByExternalSource($externalSource, $batchSize);
    }

    public function findLiveByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->inner->findLiveByUserAndPeriod($userId, $from, $to);
    }

    public function findLivePageByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, int $offset, int $limit): array
    {
        return $this->inner->findLivePageByUsersAndPeriod($userIds, $from, $to, $offset, $limit);
    }

    public function countLiveByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $this->inner->countLiveByUsersAndPeriod($userIds, $from, $to);
    }

    public function saveAll(array $entries): void
    {
        ++$this->saveAllCalls;
        $this->inner->saveAll($entries);
    }

    public function removeImported(ImportedTimeEntryScope $scope, int $batchSize = 500): ImportedTimeEntryRemoval
    {
        return $this->inner->removeImported($scope, $batchSize);
    }

    public function findPageByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, int $offset, int $limit): array
    {
        return $this->inner->findPageByUsersAndPeriod($userIds, $from, $to, $offset, $limit);
    }

    public function countByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $this->inner->countByUsersAndPeriod($userIds, $from, $to);
    }

    public function save(TimeEntry $entry): void
    {
        $this->inner->save($entry);
    }

    public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->inner->findByUserAndPeriod($userId, $from, $to);
    }

    public function canRetryAfterFailedSave(): bool
    {
        return $this->inner->canRetryAfterFailedSave();
    }
}
