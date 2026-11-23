<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Closure;
use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\LockingFindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;

use function array_filter;
use function array_values;

/**
 * 1.40.0: an in-memory store with every capability the entry corrections use (find, row lock, owner transaction), whose
 * period query returns the user's entries (overlap checks), and a hook for what another request changed meanwhile.
 */
final class CorrectableInMemoryTimeEntryRepository implements TimeEntryRepositoryInterface, LockingFindableTimeEntryRepositoryInterface, UserSerialisedTimeEntryRepositoryInterface
{
    /** @var array<string, TimeEntry> */
    private array $entries = [];

    public int $saves = 0;

    public int $transactions = 0;

    /** @var (Closure(TimeEntry): ?TimeEntry)|null what happened to the row before the lock (a replacement, null = deleted) */
    public ?Closure $meanwhile = null;

    public function save(TimeEntry $entry): void
    {
        ++$this->saves;
        $this->entries[$entry->getId()] = $entry;
    }

    public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter($this->entries, static fn (TimeEntry $entry): bool => $entry->getUserId() === $userId && $entry->getStartedAt() >= $from && $entry->getStartedAt() <= $to));
    }

    public function findById(string $id): ?TimeEntry
    {
        return $this->entries[$id] ?? null;
    }

    public function findForUpdate(string $id): ?TimeEntry
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry instanceof TimeEntry && $this->meanwhile instanceof Closure) {
            $entry = ($this->meanwhile)($entry);
        }

        return $entry;
    }

    public function inUserTransaction(object $user, callable $operation): mixed
    {
        ++$this->transactions;

        return $operation();
    }
}
