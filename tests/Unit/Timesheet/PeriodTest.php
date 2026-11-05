<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Timesheet\Period;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const DATE_ATOM;

final class PeriodTest extends TestCase
{
    public function testIsoWeekContainingADate(): void
    {
        $period = Period::containing(TimesheetPeriodKind::Week, '2026-11-05');

        self::assertSame('2026-W45', $period->key);
        self::assertSame('2026-11-02', $period->start);
        self::assertSame('2026-11-08', $period->end);
        self::assertSame(TimesheetPeriodKind::Week, $period->kind);
        self::assertTrue($period->contains('2026-11-08'));
        self::assertFalse($period->contains('2026-11-09'));
        self::assertCount(7, $period->dates());
        self::assertSame('2026-W44', $period->previous()->key);
        self::assertSame('2026-W46', $period->next()->key);
    }

    public function testIsoWeekAcrossTheYearEnd(): void
    {
        $period = Period::containing(TimesheetPeriodKind::Week, '2027-01-01');

        self::assertSame('2026-W53', $period->key);
        self::assertSame('2026-12-28', $period->start);
        self::assertSame('2027-01-03', $period->end);
        self::assertSame('2027-W01', $period->next()->key);
    }

    public function testMonth(): void
    {
        $period = Period::containing(TimesheetPeriodKind::Month, '2028-02-15');

        self::assertSame('2028-02', $period->key);
        self::assertSame('2028-02-01', $period->start);
        self::assertSame('2028-02-29', $period->end);
        self::assertCount(29, $period->dates());
        self::assertSame('2028-01', $period->previous()->key);
        self::assertSame('2028-03', $period->next()->key);
    }

    public function testInstantsInAZone(): void
    {
        $period = Period::fromKey(TimesheetPeriodKind::Week, '2026-W13');

        self::assertSame('2026-03-23T00:00:00+01:00', $period->startsAt('Europe/Madrid')->format(DATE_ATOM));
        // The last Sunday of March 2026 is the DST change: the period still ends at local midnight.
        self::assertSame('2026-03-30T00:00:00+02:00', $period->endsBefore('Europe/Madrid')->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{TimesheetPeriodKind, string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'week pattern' => [TimesheetPeriodKind::Week, '2026-45'];
        yield 'week zero' => [TimesheetPeriodKind::Week, '2026-W00'];
        yield 'week 53 of a 52-week year' => [TimesheetPeriodKind::Week, '2025-W53'];
        yield 'month pattern' => [TimesheetPeriodKind::Month, '2026-W45'];
        yield 'month 13' => [TimesheetPeriodKind::Month, '2026-13'];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRefused(TimesheetPeriodKind $kind, string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        Period::fromKey($kind, $key);
    }

    public function testInvalidDatesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Period::date('2026-02-30');
    }
}
