<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarWorkingHours;
use Nowo\TimeTrackBundle\Tests\Stub\DaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.14 (contract §6.7, AC-19): the scheduled sync reads a calendar only inside its owner's working hours; unknown
 * or malformed hours are never inside.
 */
final class CalendarWorkingHoursTest extends TestCase
{
    public function testInsideAndOutsideTheSlots(): void
    {
        $hours = new CalendarWorkingHours(new DaysOffProfileProvider(['timezone' => 'Europe/Madrid', 'slots' => [['day' => 3, 'from' => '09:00', 'to' => '17:00'], ['day' => 4, 'from' => '20:00', 'to' => '24:00']]], null));
        $user  = new TestUser('1', 'u@example.com');

        self::assertTrue($hours->contains($user, new DateTimeImmutable('2026-11-04T08:00:00Z')), 'Wednesday 09:00 Madrid');
        self::assertFalse($hours->contains($user, new DateTimeImmutable('2026-11-04T16:00:00Z')), 'Wednesday 17:00 Madrid (end exclusive)');
        self::assertFalse($hours->contains($user, new DateTimeImmutable('2026-11-04T07:59:00Z')));
        self::assertTrue($hours->contains($user, new DateTimeImmutable('2026-11-05T22:59:30Z')), 'Thursday 23:59 Madrid (end of day inclusive)');
        self::assertSame('Europe/Madrid', $hours->timezone($user)->getName());
    }

    public function testUnknownOrMalformedHoursAreNeverInside(): void
    {
        $user = new TestUser('1', 'u@example.com');
        $now  = new DateTimeImmutable('2026-11-04T10:00:00Z');

        self::assertFalse((new CalendarWorkingHours(new DaysOffProfileProvider(null, null)))->contains($user, $now));
        self::assertSame('UTC', (new CalendarWorkingHours(new DaysOffProfileProvider(null, null)))->timezone($user)->getName());
        self::assertFalse((new CalendarWorkingHours(new DaysOffProfileProvider(['timezone' => 'Mars/Base', 'slots' => [['day' => 3, 'from' => '00:00', 'to' => '24:00']]], null)))->contains($user, $now));
        self::assertTrue((new CalendarWorkingHours(new DaysOffProfileProvider(['slots' => [['day' => 3, 'from' => '10:00', 'to' => '11:00']]], null)))->contains($user, $now), 'no zone = UTC');
    }
}
