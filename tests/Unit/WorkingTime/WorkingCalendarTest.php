<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\WorkingTime;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\NullClientUserProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\DaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayKind;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayNormalizer;
use Nowo\TimeTrackBundle\WorkingTime\DerivedWorkingCalendar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_map;

use const DATE_ATOM;

final class WorkingCalendarTest extends TestCase
{
    public function testTheNormalizerFailsClosedDayByDay(): void
    {
        $logger = new RecordingLogger();
        $days   = CalendarDayNormalizer::normalize([
            new CalendarDay('2026-11-02', 'Europe/Madrid', 28_800, CalendarDayKind::Working, 'not a holiday'),
            new CalendarDay('2026-11-02', 'Europe/Madrid', 1, CalendarDayKind::Working),
            new CalendarDay('2026-11-03', 'Europe/Madrid', 0, CalendarDayKind::Holiday, 'Fiesta'),
            new CalendarDay('2026-02-30', 'UTC', 0, CalendarDayKind::Rest),
            new CalendarDay('2026-12-01', 'UTC', 0, CalendarDayKind::Rest),
            new CalendarDay('2026-11-04', 'UTC', 90_000, CalendarDayKind::Working),
            new CalendarDay('2026-11-05', 'Mars/Base', 0, CalendarDayKind::Rest),
            new CalendarDay('2026-11-06', 'UTC', 0, CalendarDayKind::Rest, null, 1.5),
            new CalendarDay('2026-11-07', 'UTC', 0, CalendarDayKind::Rest, null, 1.0, -1),
        ], '2026-11-02', '2026-11-08', 'UTC', $logger);

        self::assertNotNull($days);
        self::assertSame(['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'], array_keys($days));
        self::assertNull($days['2026-11-02']->label, 'labels are for public holidays only');
        self::assertSame(28_800, $days['2026-11-02']->expectedSeconds, 'the first of two days for a date wins');
        self::assertSame('Fiesta', $days['2026-11-03']->label);
        foreach (['2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'] as $date) {
            self::assertSame(CalendarDayKind::None, $days[$date]->kind);
        }
        self::assertSame(['duplicate', 'date', 'out_of_range', 'expected_seconds', 'timezone', 'fraction', 'ordinary_max_seconds'], array_map(static fn (array $r): mixed => $r['context']['reason'], $logger->records));
        self::assertNull(CalendarDayNormalizer::normalize(null, '2026-11-02', '2026-11-08', 'UTC'));
    }

    public function testTheDerivedCalendarUsesWorkingHoursAndDaysOff(): void
    {
        $profile = new DaysOffProfileProvider(['timezone' => 'Europe/Madrid', 'slots' => [
            ['day' => 1, 'from' => '09:00', 'to' => '14:00'],
            ['day' => 1, 'from' => '15:00', 'to' => '18:00'],
            ['day' => 2, 'from' => '22:00', 'to' => '24:00'],
            ['day' => 5, 'from' => '08:00', 'to' => '15:00'],
        ]], [['date' => '2026-11-06', 'kind' => 'vacation', 'label' => 'Beach'], ['date' => '2026-11-09', 'kind' => 'holiday', 'label' => 'Almudena']]);
        $calendar = new DerivedWorkingCalendar($profile);

        $days = $calendar->days(new TestUser('7', 'a@example.com'), 'org-a', new DateTimeImmutable('2026-11-02'), new DateTimeImmutable('2026-11-09'));

        self::assertNotNull($days);
        self::assertSame([28_800, 7200, 0, 0, 0, 0, 0, 0], array_column(array_map(static fn (CalendarDay $d): array => ['s' => $d->expectedSeconds], $days), 's'));
        self::assertSame([CalendarDayKind::Working, CalendarDayKind::Working, CalendarDayKind::Rest, CalendarDayKind::Rest, CalendarDayKind::Vacation, CalendarDayKind::Rest, CalendarDayKind::Rest, CalendarDayKind::Holiday], array_map(static fn (CalendarDay $d): CalendarDayKind => $d->kind, $days));
        self::assertNull($days[4]->label, 'vacation labels are never kept');
        self::assertSame('Almudena', $days[7]->label);
        self::assertSame('Europe/Madrid', $days[0]->timezone);
        self::assertSame('2026-11-02T00:00:00+01:00', $profile->from?->format(DATE_ATOM));
        self::assertSame('2026-11-09T23:59:59+01:00', $profile->to?->format(DATE_ATOM));
        self::assertNull((new DerivedWorkingCalendar(new NullClientUserProfileProvider()))->days(new TestUser('7', 'a@example.com'), 'org-a', new DateTimeImmutable('2026-11-02'), new DateTimeImmutable('2026-11-09')));
    }

    public function testADerivedCalendarWithoutDaysOff(): void
    {
        $profile = new class implements ClientUserProfileProviderInterface {
            public function getDisplayName(UserInterface $user): ?string
            {
                return null;
            }

            public function getWorkingHours(UserInterface $user): array
            {
                return ['timezone' => null, 'slots' => [['day' => 3, 'from' => '09:00', 'to' => '10:00']]];
            }
        };

        $days = (new DerivedWorkingCalendar($profile))->days(new TestUser('7', 'a@example.com'), 'org-a', new DateTimeImmutable('2026-11-04'), new DateTimeImmutable('2026-11-04'));

        self::assertNotNull($days);
        self::assertSame(3600, $days[0]->expectedSeconds);
    }

    public function testTheZoneResolverOrder(): void
    {
        $user     = new TestUser('7', 'a@example.com');
        $scopes   = new FixedScopeProvider(['7' => ['org-a', 'org-b', 'org-c']], ['org-a' => 'America/New_York', 'org-c' => 'Bad/Zone']);
        $profile  = new DaysOffProfileProvider(['timezone' => 'Europe/Lisbon', 'slots' => []], null);
        $resolver = new TimesheetTimezoneResolver($scopes, $profile, 'Europe/Madrid');
        self::assertSame('America/New_York', $resolver->resolve($user, 'org-a'));
        self::assertSame('Europe/Lisbon', $resolver->resolve($user, 'org-b'));
        self::assertSame('Europe/Lisbon', $resolver->resolve($user, 'org-c'));
        self::assertSame('Europe/Madrid', (new TimesheetTimezoneResolver(null, new NullClientUserProfileProvider(), 'Europe/Madrid'))->resolve($user, 'org-a'));
        self::assertSame('UTC', (new TimesheetTimezoneResolver())->resolve($user, 'org-a'));
        self::assertFalse(TimesheetTimezoneResolver::isValid(''));
        self::assertFalse(TimesheetTimezoneResolver::isValid(3));
    }
}
