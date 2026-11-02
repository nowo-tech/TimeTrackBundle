<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\PaginatedTimeEntryRepositoryInterface;

use function array_slice;
use function count;

/**
 * In-memory paginated repository that records the page queries it receives.
 */
final class InMemoryPaginatedTimeEntryRepository implements PaginatedTimeEntryRepositoryInterface
{
    /** @var list<array{userIds: list<string>, offset: int, limit: int}> */
    public array $pageQueries = [];

    public int $countQueries = 0;

    private readonly InMemoryTimeEntryRepository $inner;

    public function __construct()
    {
        $this->inner = new InMemoryTimeEntryRepository();
    }

    public function save(TimeEntry $entry): void
    {
        $this->inner->save($entry);
    }

    public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->inner->findByUserAndPeriod($userId, $from, $to);
    }

    public function findPageByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to, int $offset, int $limit): array
    {
        $this->pageQueries[] = ['userIds' => $userIds, 'offset' => $offset, 'limit' => $limit];

        return array_slice($this->all($userIds, $from, $to), $offset, $limit);
    }

    public function countByUsersAndPeriod(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        ++$this->countQueries;

        return count($this->all($userIds, $from, $to));
    }

    /**
     * @param list<string> $userIds
     *
     * @return list<TimeEntry>
     */
    private function all(array $userIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $entries = [];
        foreach ($userIds as $userId) {
            foreach ($this->inner->findByUserAndPeriod($userId, $from, $to) as $entry) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn (TimeEntry $a, TimeEntry $b): int => $b->getStartedAt() <=> $a->getStartedAt());

        return $entries;
    }
}
