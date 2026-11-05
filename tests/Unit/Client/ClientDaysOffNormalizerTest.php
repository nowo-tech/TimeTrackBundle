<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientDaysOffNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function str_repeat;

/**
 * 1.12: strict validation of the host's days off for `GET me.daysOff`.
 */
final class ClientDaysOffNormalizerTest extends TestCase
{
    private const FROM = '2026-11-02';

    private const TO = '2027-01-01';

    public function testNullStaysNull(): void
    {
        self::assertNull(ClientDaysOffNormalizer::normalize(null, self::FROM, self::TO));
    }

    public function testValidEntriesAreFilteredDedupedAndSorted(): void
    {
        self::assertSame([
            ['date' => '2026-11-02', 'kind' => 'vacation', 'label' => null],
            ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
            ['date' => '2026-12-25', 'kind' => 'holiday', 'label' => '<b>Christmas</b> & co'],
            ['date' => '2027-01-01', 'kind' => 'holiday', 'label' => null],
        ], ClientDaysOffNormalizer::normalize([
            ['date' => '2026-12-25', 'kind' => 'holiday', 'label' => '  <b>Christmas</b> & co  '],
            ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción', 'extra' => true],
            ['date' => '2026-11-02', 'kind' => 'vacation'],
            ['date' => '2026-12-25', 'kind' => 'vacation', 'label' => 'duplicate date: first wins'],
            ['date' => '2027-01-01', 'kind' => 'holiday', 'label' => '   '],
            ['date' => '2026-11-01', 'kind' => 'holiday', 'label' => 'before the range'],
            ['date' => '2027-01-02', 'kind' => 'holiday', 'label' => 'after the range'],
        ], self::FROM, self::TO));
        self::assertSame([], ClientDaysOffNormalizer::normalize([], self::FROM, self::TO));
    }

    public function testLongLabelsAreCutToTheCap(): void
    {
        $label = str_repeat('é', ClientDaysOffNormalizer::LABEL_MAX_LENGTH);
        $days  = ClientDaysOffNormalizer::normalize([['date' => '2026-11-03', 'kind' => 'holiday', 'label' => $label . 'overflow']], self::FROM, self::TO);

        self::assertSame($label, $days[0]['label'] ?? null);

        $cut = ClientDaysOffNormalizer::normalize([['date' => '2026-11-03', 'kind' => 'holiday', 'label' => str_repeat('a', 79) . ' tail']], self::FROM, self::TO);
        self::assertSame(str_repeat('a', 79), $cut[0]['label'] ?? null);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function providerInvalid(): iterable
    {
        $ok = ['date' => '2026-11-03', 'kind' => 'holiday'];

        yield 'not a list' => [['a' => $ok]];
        yield 'too many entries' => [array_fill(0, ClientDaysOffNormalizer::MAX_ENTRIES + 1, $ok)];
        yield 'entry not an array' => [['2026-11-03']];
        yield 'missing date' => [[['kind' => 'holiday']]];
        yield 'date not a string' => [[['date' => 20261103, 'kind' => 'holiday']]];
        yield 'date with time' => [[['date' => '2026-11-03T00:00:00', 'kind' => 'holiday']]];
        yield 'date in another format' => [[['date' => '03/11/2026', 'kind' => 'holiday']]];
        yield 'impossible date' => [[['date' => '2026-02-30', 'kind' => 'holiday']]];
        yield 'unknown kind' => [[['date' => '2026-11-03', 'kind' => 'sick']]];
        yield 'missing kind' => [[['date' => '2026-11-03']]];
        yield 'label not a string' => [[['date' => '2026-11-03', 'kind' => 'holiday', 'label' => 42]]];
        yield 'label with a control character' => [[['date' => '2026-11-03', 'kind' => 'holiday', 'label' => "line\nbreak"]]];
        yield 'label not UTF-8' => [[['date' => '2026-11-03', 'kind' => 'holiday', 'label' => "\xff\xfe"]]];
        yield 'one bad entry among good ones' => [[$ok, ['date' => '2026-11-04', 'kind' => 'Holiday']]];
    }

    /**
     * @param array<mixed> $daysOff
     */
    #[DataProvider('providerInvalid')]
    public function testMalformedDaysOffAreNull(array $daysOff): void
    {
        self::assertNull(ClientDaysOffNormalizer::normalize($daysOff, self::FROM, self::TO));
    }
}
