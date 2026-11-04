<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Service\TimerService;

/**
 * Time entry store that can return the end of a user's latest entry in one query (since 1.10). {@see TimerService}
 * uses it to keep a backdated start (`startedAt` on `timer/start`) from overlapping the previous entry. A store that only
 * implements {@see TimeEntryRepositoryInterface} is read through `findByUserAndPeriod()` instead.
 */
interface LatestTimeEntryRepositoryInterface extends TimeEntryRepositoryInterface
{
    /**
     * Latest `endedAt` of the user's entries, or null when the user has none.
     */
    public function findLatestEndedAt(string $userId): ?DateTimeImmutable;
}
