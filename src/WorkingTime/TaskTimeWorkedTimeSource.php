<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function max;
use function usort;

/**
 * Since 1.13: the default worked-time source: **live** task time of the scope (imported entries excluded), by local
 * start date in the scope's zone. Overlapping live entries of a day are merged (union of intervals), so two parallel
 * timers never double-count. Not a working-time register: `legalRecord` is false (contract §4.3, §12.1).
 */
final readonly class TaskTimeWorkedTimeSource implements WorkedTimeSourceInterface
{
    /**
     * Receives the entry store and the period zone resolver.
     */
    public function __construct(
        private TimeEntryRepositoryInterface $entries,
        private TimesheetTimezoneResolver $timezoneResolver,
    ) {
    }

    /**
     * Task time is never the legal register.
     */
    public function isLegalRecord(): bool
    {
        return false;
    }

    /**
     * Union of the live entries of the scope per local start date.
     */
    public function workedDays(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $timezone = $this->timezoneResolver->resolve($user, $scopeKey);
        $start    = CalendarDayNormalizer::midnight($from->format('Y-m-d'), $timezone);
        $end      = CalendarDayNormalizer::midnight($to->format('Y-m-d'), $timezone)->modify('+1 day -1 second');

        /** @var array<string, list<array{int, int}>> $intervals */
        $intervals = [];
        foreach ($this->entries->findByUserAndPeriod(UserIdResolver::getId($user), $start, $end) as $entry) {
            if (!$entry->getSource()->isLive() || $entry->getScopeKey() !== $scopeKey) {
                continue;
            }
            $intervals[StorageTime::localDate($entry->getStartedAt(), $timezone)][] = [$entry->getStartedAt()->getTimestamp(), $entry->getEndedAt()->getTimestamp()];
        }

        $days = [];
        foreach ($intervals as $date => $spans) {
            usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $worked              = 0;
            [$curStart, $curEnd] = $spans[0];
            $first               = $curStart;
            $last                = $curEnd;
            foreach ($spans as [$s, $e]) {
                $last = max($last, $e);
                if ($s > $curEnd) {
                    $worked += $curEnd - $curStart;
                    [$curStart, $curEnd] = [$s, $e];
                    continue;
                }
                $curEnd = max($curEnd, $e);
            }
            $worked += $curEnd - $curStart;
            $days[(string) $date] = new WorkedDay((string) $date, $worked, 0, new DateTimeImmutable('@' . $first), new DateTimeImmutable('@' . $last));
        }

        return $days;
    }
}
