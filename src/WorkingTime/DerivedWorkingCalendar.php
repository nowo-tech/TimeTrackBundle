<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientDaysOffNormalizer;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function date_default_timezone_get;
use function explode;

/**
 * Since 1.13: the default working calendar, derived from the 1.12 client providers (no host work needed): expected
 * seconds from the normalised `workingHours.slots` of the weekday (`23:59` ends a day at midnight), 1.12 `daysOff`
 * holidays and vacations → 0, zone from `workingHours`. Null when the working hours are unknown. Days off only cover
 * what the host's `getDaysOff()` returns for the asked range.
 */
final readonly class DerivedWorkingCalendar implements WorkingCalendarProviderInterface
{
    /**
     * Receives the `client_api.profile_provider` service.
     */
    public function __construct(
        private ClientUserProfileProviderInterface $profileProvider,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Calendar days of the range from the working hours and days off.
     */
    public function days(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $hours = ClientWorkingHoursNormalizer::normalize($this->profileProvider->getWorkingHours($user), $this->logger);
        if ($hours === null) {
            return null;
        }

        $timezone = $hours['timezone'] ?? date_default_timezone_get();
        $weekday  = [];
        foreach ($hours['slots'] as $slot) {
            $weekday[$slot['day']] = ($weekday[$slot['day']] ?? 0) + $this->seconds($slot['to'] === '23:59' ? '24:00' : $slot['to']) - $this->seconds($slot['from']);
        }

        $fromDate = $from->format('Y-m-d');
        $toDate   = $to->format('Y-m-d');
        $off      = [];
        if ($this->profileProvider instanceof ClientUserDaysOffProviderInterface) {
            $range = $this->profileProvider->getDaysOff($user, CalendarDayNormalizer::midnight($fromDate, $timezone), CalendarDayNormalizer::midnight($toDate, $timezone)->modify('+1 day -1 second'));
            foreach (ClientDaysOffNormalizer::normalize($range, $fromDate, $toDate, $this->logger) ?? [] as $dayOff) {
                $off[$dayOff['date']] = $dayOff;
            }
        }

        $days = [];
        for ($date = Period::date($fromDate); $date->format('Y-m-d') <= $toDate; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            if (isset($off[$key])) {
                $holiday = $off[$key]['kind'] === 'holiday';
                $days[]  = new CalendarDay($key, $timezone, 0, $holiday ? CalendarDayKind::Holiday : CalendarDayKind::Vacation, $holiday ? $off[$key]['label'] : null);
                continue;
            }
            $expected = $weekday[(int) $date->format('N')] ?? 0;
            $days[]   = new CalendarDay($key, $timezone, $expected, $expected > 0 ? CalendarDayKind::Working : CalendarDayKind::Rest);
        }

        return $days;
    }

    private function seconds(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 3600 + (int) $m * 60;
    }
}
