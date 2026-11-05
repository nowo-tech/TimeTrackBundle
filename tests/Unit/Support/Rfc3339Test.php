<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use DateTimeZone;
use Nowo\TimeTrackBundle\Support\Rfc3339;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.14: the strict RFC 3339 profile of the 1.14 client routes.
 */
final class Rfc3339Test extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, string|null}>
     */
    public static function values(): iterable
    {
        yield 'Z' => ['2026-11-05T08:00:00Z', false, '2026-11-05T08:00:00Z'];
        yield 'offset' => ['2026-11-05T09:00:00+01:00', false, '2026-11-05T08:00:00Z'];
        yield 'fraction dropped' => ['2026-11-05T09:00:00.123456+01:00', false, '2026-11-05T08:00:00Z'];
        yield '+14:00' => ['2026-11-05T14:00:00+14:00', false, '2026-11-05T00:00:00Z'];
        yield '-12:00' => ['2026-11-05T00:00:00-12:00', false, '2026-11-05T12:00:00Z'];
        yield 'no seconds when optional' => ['2026-11-05T08:00Z', true, '2026-11-05T08:00:00Z'];
        yield 'no seconds when required' => ['2026-11-05T08:00Z', false, null];
        yield 'no offset' => ['2026-11-05T08:00:00', false, null];
        yield 'date only' => ['2026-11-05', true, null];
        yield 'week' => ['2026-W45', true, null];
        yield 'decoded plus' => ['2026-11-05T09:00:00 01:00', true, null];
        yield 'seven fraction digits' => ['2026-11-05T09:00:00.1234567Z', false, null];
        yield 'impossible date' => ['2026-02-30T09:00:00Z', false, null];
        yield 'impossible hour' => ['2026-11-05T25:00:00Z', false, null];
        yield '+15:00' => ['2026-11-05T09:00:00+15:00', false, null];
        yield '-13:00' => ['2026-11-05T09:00:00-13:00', false, null];
        yield 'surrounding space' => [' 2026-11-05T09:00:00Z', false, null];
    }

    #[DataProvider('values')]
    public function testParses(string $value, bool $secondsOptional, ?string $expected): void
    {
        $parsed = Rfc3339::parse($value, $secondsOptional);

        self::assertSame($expected, $parsed?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
    }

    public function testOnlyStrings(): void
    {
        self::assertNull(Rfc3339::parse(null));
        self::assertNull(Rfc3339::parse(20261105));
    }
}
