<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\OverlappingTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;

/**
 * Since 1.40.0 (shared by the manual entries since 1.14 and the corrections): whether a span overlaps the user's running
 * timer or one of the user's live entries. Read inside the caller's unit, under the owner's lock. Stateless.
 *
 * @internal
 */
final class EntryOverlap
{
    /**
     * Whether `[start, end)` overlaps the user's running timer (started before the end) or a live entry of the user other
     * than `$exceptEntryId`.
     */
    public static function exists(TimeEntryRepositoryInterface $entries, ActiveTimerRepositoryInterface $timers, string $userId, DateTimeImmutable $start, DateTimeImmutable $end, ?string $exceptEntryId = null): bool
    {
        $timer = $timers->findByUserId($userId);
        if ($timer instanceof ActiveTimer && $timer->getStartedAt() < $end) {
            return true;
        }
        $candidates = $entries instanceof OverlappingTimeEntryRepositoryInterface
            ? $entries->findLiveOverlapping($userId, $start, $end)
            : $entries->findByUserAndPeriod($userId, $start->modify('-2 days'), $end);
        foreach ($candidates as $entry) {
            if ($entry->getId() !== $exceptEntryId && $entry->getSource()->isLive() && $entry->getStartedAt() < $end && $entry->getEndedAt() > $start) {
                return true;
            }
        }

        return false;
    }
}
