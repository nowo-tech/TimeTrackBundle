<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;

final class InMemoryTimeEntryRepository implements TimeEntryRepositoryInterface
{
    /** @var list<TimeEntry> */
    private array $entries = [];

    public function save(TimeEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter(
            $this->entries,
            static function (TimeEntry $entry) use ($userId, $from, $to): bool {
                $user = $entry->getUser();

                return (string) $user->getId() === $userId
                    && $entry->getStartedAt() >= $from
                    && $entry->getStartedAt() <= $to;
            },
        ));
    }
}
