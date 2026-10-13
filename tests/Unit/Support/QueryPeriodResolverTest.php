<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const DATE_ATOM;

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
        self::assertSame(
            '2026-05-01T10:30:00+02:00',
            QueryPeriodResolver::parseDate('2026-05-01T10:30:00+02:00', 'now')->format(DATE_ATOM),
        );
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
}
