<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function date_default_timezone_get;

final class QueryPeriodResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function garbageProvider(): iterable
    {
        yield 'free text' => ['not a date'];
        yield 'relative format' => ['next thursday +3 weeks'];
        yield 'overflowing month' => ['2026-13-45'];
        yield 'overflowing atom' => ['2026-02-30T25:61:00+00:00'];
        yield 'null byte' => ["2026-01-01\0"];
        yield 'timestamp' => ['@99999999999999999999'];
    }

    #[DataProvider('garbageProvider')]
    public function testGarbageFallsBackToDefault(string $value): void
    {
        $before = new DateTimeImmutable('-7 days -1 minute');
        $parsed = QueryPeriodResolver::parseDate($value, '-7 days');

        self::assertGreaterThan($before, $parsed);
        self::assertLessThanOrEqual(new DateTimeImmutable('-7 days +1 minute'), $parsed);
    }

    public function testParsesAtom(): void
    {
        $parsed = QueryPeriodResolver::parseDate('2026-05-01T10:30:00+02:00', 'now');

        self::assertSame((new DateTimeImmutable('2026-05-01T10:30:00+02:00'))->getTimestamp(), $parsed->getTimestamp());
        self::assertSame(date_default_timezone_get(), $parsed->getTimezone()->getName(), 'converted to the server zone (EF-M1)');
    }

    public function testOffsetsAreConvertedToTheServerZone(): void
    {
        $now    = new DateTimeImmutable('2026-11-02 10:00:00', new DateTimeZone('Asia/Tokyo'));
        $parsed = QueryPeriodResolver::parseDate('2026-11-02T08:00:00-11:00', '-7 days', $now);

        self::assertSame((new DateTimeImmutable('2026-11-02T19:00:00+00:00'))->getTimestamp(), $parsed->getTimestamp());
        self::assertSame(date_default_timezone_get(), $parsed->getTimezone()->getName(), 'the zone Doctrine binds and hydrates in');
    }

    public function testParsesDateOnlyAtMidnight(): void
    {
        self::assertSame('2026-05-01 00:00:00', QueryPeriodResolver::parseDate('2026-05-01', 'now')->format('Y-m-d H:i:s'));
    }

    public function testResolveUsesDefaultsWhenEmpty(): void
    {
        $period = QueryPeriodResolver::resolve('', '');

        self::assertEqualsWithDelta(7 * 86400, $period['to']->getTimestamp() - $period['from']->getTimestamp(), 5);
    }

    public function testResolveCapsSpan(): void
    {
        $period = QueryPeriodResolver::resolve('1970-01-01', '2026-01-01');

        self::assertSame('2026-01-01', $period['to']->format('Y-m-d'));
        self::assertSame(
            (new DateTimeImmutable('2026-01-01'))->modify('-' . QueryPeriodResolver::MAX_SPAN_DAYS . ' days')->format('Y-m-d'),
            $period['from']->format('Y-m-d'),
        );
    }

    public function testResolveKeepsSpanWithinLimit(): void
    {
        $period = QueryPeriodResolver::resolve('2025-06-01', '2026-01-01');

        self::assertSame('2025-06-01', $period['from']->format('Y-m-d'));
    }

    public function testDefaultsAreRelativeToTheGivenTime(): void
    {
        $now    = new DateTimeImmutable('2026-05-04 12:00:00');
        $period = QueryPeriodResolver::resolve('', 'garbage', $now);

        self::assertSame('2026-04-27 12:00:00', $period['from']->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-04 12:00:00', $period['to']->format('Y-m-d H:i:s'));
    }
}
