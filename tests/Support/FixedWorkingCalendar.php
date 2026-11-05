<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayKind;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Test calendar: weekdays `$weekdaySeconds`, weekends rest, plus per-date overrides; null for unknown scopes.
 */
final class FixedWorkingCalendar implements WorkingCalendarProviderInterface
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    /**
     * @param array<string, CalendarDay> $overrides
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly string $timezone = 'Europe/Madrid',
        private readonly int $weekdaySeconds = 28_800,
        private readonly array $overrides = [],
        private readonly array $scopes = ['org-a'],
    ) {
    }

    public function days(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $this->calls[] = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        if (!in_array($scopeKey, $this->scopes, true)) {
            return null;
        }
        $days = [];
        for ($date = Period::date($from->format('Y-m-d')); $date <= Period::date($to->format('Y-m-d')); $date = $date->modify('+1 day')) {
            $key    = $date->format('Y-m-d');
            $days[] = $this->overrides[$key] ?? ((int) $date->format('N') <= 5
                ? new CalendarDay($key, $this->timezone, $this->weekdaySeconds, CalendarDayKind::Working)
                : new CalendarDay($key, $this->timezone, 0, CalendarDayKind::Rest));
        }

        return $days;
    }
}
