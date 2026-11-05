<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;

/**
 * 1.12: `GET me.workingHours` is normalised to the `slots` shape from either provider shape; malformed input is null.
 */
final class ClientWorkingHoursNormalizerTest extends TestCase
{
    public function testNullStaysNull(): void
    {
        self::assertNull(ClientWorkingHoursNormalizer::normalize(null));
    }

    public function testTheSlotsShapeIsSortedAndPassedThrough(): void
    {
        self::assertSame([
            'timezone' => 'Europe/Madrid',
            'slots'    => [
                ['day' => 1, 'from' => '09:00', 'to' => '14:00'],
                ['day' => 1, 'from' => '15:00', 'to' => '18:00'],
                ['day' => 5, 'from' => '08:00', 'to' => '15:00'],
            ],
        ], ClientWorkingHoursNormalizer::normalize([
            'timezone' => 'Europe/Madrid',
            'slots'    => [
                ['day' => 5, 'from' => '8:00', 'to' => '15:00'],
                ['day' => 1, 'from' => '15:00', 'to' => '18:00'],
                ['day' => 1, 'from' => '09:00', 'to' => '14:00', 'extra' => 'ignored'],
            ],
        ]));
    }

    public function testTheDocumentedDaysShapeBecomesSlots(): void
    {
        self::assertSame([
            'timezone' => 'Europe/Madrid',
            'slots'    => [
                ['day' => 1, 'from' => '09:00', 'to' => '14:00'],
                ['day' => 1, 'from' => '15:00', 'to' => '18:00'],
                ['day' => 2, 'from' => '09:00', 'to' => '17:00'],
                ['day' => 7, 'from' => '10:00', 'to' => '12:00'],
            ],
        ], ClientWorkingHoursNormalizer::normalize([
            'timezone' => 'Europe/Madrid',
            'days'     => [
                'mon'     => [['09:00', '14:00'], ['15:00', '18:00']],
                'Tuesday' => [['from' => '09:00', 'to' => '17:00']],
                'wed'     => [],
                'SUN'     => [['10:00', '12:00']],
            ],
        ]));
    }

    public function testSlotsWinOverDaysAndTheTimezoneIsOptional(): void
    {
        self::assertSame(
            ['timezone' => null, 'slots' => [['day' => 3, 'from' => '09:00', 'to' => '10:00']]],
            ClientWorkingHoursNormalizer::normalize(['slots' => [['day' => 3, 'from' => '09:00', 'to' => '10:00']], 'days' => ['mon' => [['01:00', '02:00']]]]),
        );
        self::assertSame(['timezone' => 'UTC', 'slots' => []], ClientWorkingHoursNormalizer::normalize(['timezone' => 'UTC', 'slots' => []]));
        self::assertSame(['timezone' => null, 'slots' => []], ClientWorkingHoursNormalizer::normalize(['days' => []]));
    }

    public function testOvernightRangesAreSplitAtMidnight(): void
    {
        self::assertSame([
            'timezone' => null,
            'slots'    => [
                ['day' => 1, 'from' => '00:00', 'to' => '06:00'],
                ['day' => 5, 'from' => '22:00', 'to' => '23:59'],
                ['day' => 6, 'from' => '00:00', 'to' => '06:00'],
                ['day' => 6, 'from' => '20:00', 'to' => '23:59'],
                ['day' => 7, 'from' => '22:00', 'to' => '23:59'],
            ],
        ], ClientWorkingHoursNormalizer::normalize(['slots' => [
            ['day' => 5, 'from' => '22:00', 'to' => '06:00'],
            ['day' => 7, 'from' => '22:00', 'to' => '06:00'],
            ['day' => 6, 'from' => '20:00', 'to' => '00:00'],
        ]]));
    }

    public function testOverlappingAndTouchingRangesAreMerged(): void
    {
        self::assertSame([
            'timezone' => null,
            'slots'    => [
                ['day' => 1, 'from' => '08:00', 'to' => '13:00'],
                ['day' => 1, 'from' => '14:00', 'to' => '23:59'],
                ['day' => 2, 'from' => '00:00', 'to' => '02:00'],
            ],
        ], ClientWorkingHoursNormalizer::normalize(['days' => [
            'mon' => [['09:00', '12:00'], ['08:00', '10:00'], ['10:30', '11:00'], ['12:00', '13:00'], ['14:00', '24:00'], ['23:00', '02:00']],
        ]]));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function providerInvalid(): iterable
    {
        $slot = ['day' => 1, 'from' => '09:00', 'to' => '17:00'];

        yield 'no shape key' => [['timezone' => 'UTC']];
        yield 'unknown timezone' => [['timezone' => 'Mars/Olympus', 'slots' => [$slot]]];
        yield 'timezone not a string' => [['timezone' => 1, 'slots' => [$slot]]];
        yield 'slots not a list' => [['slots' => ['a' => $slot]]];
        yield 'slots not an array' => [['slots' => 'mon 9-17']];
        yield 'slot not an array' => [['slots' => ['09:00-17:00']]];
        yield 'day 0' => [['slots' => [['day' => 0, 'from' => '09:00', 'to' => '17:00']]]];
        yield 'day 8' => [['slots' => [['day' => 8, 'from' => '09:00', 'to' => '17:00']]]];
        yield 'day as string' => [['slots' => [['day' => '1', 'from' => '09:00', 'to' => '17:00']]]];
        yield 'missing day' => [['slots' => [['from' => '09:00', 'to' => '17:00']]]];
        yield 'bad from' => [['slots' => [['day' => 1, 'from' => '25:00', 'to' => '17:00']]]];
        yield 'bad to' => [['slots' => [['day' => 1, 'from' => '09:00', 'to' => '17:60']]]];
        yield 'seconds' => [['slots' => [['day' => 1, 'from' => '09:00:00', 'to' => '17:00']]]];
        yield '24:00 as a start' => [['slots' => [['day' => 1, 'from' => '24:00', 'to' => '06:00']]]];
        yield 'time not a string' => [['slots' => [['day' => 1, 'from' => 900, 'to' => '17:00']]]];
        yield 'empty range' => [['slots' => [['day' => 1, 'from' => '09:00', 'to' => '09:00']]]];
        yield 'too many slots' => [['slots' => array_fill(0, ClientWorkingHoursNormalizer::MAX_SLOTS + 1, $slot)]];
        yield 'days not an array' => [['days' => 'weekdays']];
        yield 'unknown day key' => [['days' => ['funday' => [['09:00', '17:00']]]]];
        yield 'numeric day key' => [['days' => [[['09:00', '17:00']]]]];
        yield 'day value not a list' => [['days' => ['mon' => ['from' => '09:00', 'to' => '17:00']]]];
        yield 'range not an array' => [['days' => ['mon' => ['09:00-17:00']]]];
        yield 'range with three values' => [['days' => ['mon' => [['09:00', '12:00', '17:00']]]]];
    }

    /**
     * @param array<mixed> $hours
     */
    #[DataProvider('providerInvalid')]
    public function testMalformedWorkingHoursAreNull(array $hours): void
    {
        self::assertNull(ClientWorkingHoursNormalizer::normalize($hours));
    }
}
