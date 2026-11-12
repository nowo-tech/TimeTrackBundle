<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\FindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;

/**
 * Since 1.21: an in-memory store that finds entries by id and runs user transactions (counted).
 */
final class FindableInMemoryTimeEntryRepository implements TimeEntryRepositoryInterface, FindableTimeEntryRepositoryInterface, UserSerialisedTimeEntryRepositoryInterface
{
    /** @var array<string, TimeEntry> */
    private array $entries = [];

    public int $saves = 0;

    public int $transactions = 0;

    public function save(TimeEntry $entry): void
    {
        ++$this->saves;
        $this->entries[$entry->getId()] = $entry;
    }

    public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [];
    }

    public function findById(string $id): ?TimeEntry
    {
        return $this->entries[$id] ?? null;
    }

    public function inUserTransaction(object $user, callable $operation): mixed
    {
        ++$this->transactions;

        return $operation();
    }
}
