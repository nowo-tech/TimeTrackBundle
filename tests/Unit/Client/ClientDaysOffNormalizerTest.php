<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientDaysOffNormalizer;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function count;
use function str_repeat;

/**
 * 1.12: validation of the host's days off for `GET me.daysOff` — bad entries are dropped alone and logged (FR-L2),
 * labels are cleaned plain text (FR-I1) cut on grapheme boundaries (FR-I2).
 */
final class ClientDaysOffNormalizerTest extends TestCase
{
    private const FROM = '2026-11-02';

    private const TO = '2027-01-01';

    public function testNullStaysNull(): void
    {
        $logger = new RecordingLogger();

        self::assertNull(ClientDaysOffNormalizer::normalize(null, self::FROM, self::TO, $logger));
        self::assertSame([], $logger->records);
    }

    public function testValidEntriesAreFilteredDedupedAndSorted(): void
    {
        $logger = new RecordingLogger();

        self::assertSame([
            ['date' => '2026-11-02', 'kind' => 'vacation', 'label' => null],
            ['date' => '2026-12-08', 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
            ['date' => '2026-12-25', 'kind' => 'holiday', 'label' => '<b>Christmas</b> & co'],
            ['date' => '2027-01-01', 'kind' => 'holiday', 'label' => null],
        ], ClientDaysOffNormalizer::normalize([
            ['date' => '2026-12-25', 'kind' => 'holiday', 'label' => '  <b>Christmas</b> & co  '],
            ['date' => '2026-12-08', 'kind' => 'Holiday', 'label' => 'Inmaculada Concepción', 'extra' => true],
            ['date' => '2026-11-02', 'kind' => ' VACATION '],
            ['date' => '2026-12-25', 'kind' => 'vacation', 'label' => 'duplicate date: first wins'],
            ['date' => '2027-01-01', 'kind' => 'holiday', 'label' => "\u{00A0}\u{3000} "],
            ['date' => '2026-11-01', 'kind' => 'holiday', 'label' => 'before the range'],
            ['date' => '2027-01-02', 'kind' => 'holiday', 'label' => 'after the range'],
        ], self::FROM, self::TO, $logger));
        self::assertSame([], ClientDaysOffNormalizer::normalize([], self::FROM, self::TO));
        self::assertSame([], $logger->records);
    }

    public function testLabelsAreCleanedPlainText(): void
    {
        $labels = static fn (string $label): ?string => ClientDaysOffNormalizer::normalize([['date' => '2026-11-03', 'kind' => 'holiday', 'label' => $label]], self::FROM, self::TO)[0]['label'] ?? null;

        self::assertSame('line break', $labels("line\nbreak"));
        self::assertSame('tab del c1', $labels("tab\tdel\x7f\u{0085}c1"));
        self::assertSame('evil.exe', $labels("\u{202E}evil\u{200B}.exe\u{FEFF}"));
        self::assertSame('Fiesta', $labels("\u{2066}Fiesta\u{2069}\u{200F}"));
        self::assertNull($labels("\u{200B}\u{00A0}"));
        self::assertSame("👨\u{200D}👩\u{200D}👧", $labels("👨\u{200D}👩\u{200D}👧"));
    }

    #[RequiresPhpExtension('intl')]
    public function testLongLabelsAreCutOnGraphemeBoundaries(): void
    {
        $cut = static fn (string $label): ?string => ClientDaysOffNormalizer::normalize([['date' => '2026-11-03', 'kind' => 'holiday', 'label' => $label]], self::FROM, self::TO)[0]['label'] ?? null;

        $composed = str_repeat("e\u{0301}", ClientDaysOffNormalizer::LABEL_MAX_LENGTH);
        self::assertSame($composed, $cut($composed . 'overflow'));
        self::assertSame(str_repeat('a', 79), $cut(str_repeat('a', 79) . ' tail'));

        $family = str_repeat("👨\u{200D}👩\u{200D}👧", ClientDaysOffNormalizer::LABEL_MAX_LENGTH);
        self::assertSame($family, $cut($family . '👨'));
    }

    /**
     * @return iterable<string, array{array<mixed>, list<string>}>
     */
    public static function providerBadEntries(): iterable
    {
        yield 'entry not an array' => [['2026-11-03'], ['entry is not an object']];
        yield 'missing date' => [[['kind' => 'holiday']], ['invalid date']];
        yield 'date not a string' => [[['date' => 20261103, 'kind' => 'holiday']], ['invalid date']];
        yield 'date with time' => [[['date' => '2026-11-03T00:00:00', 'kind' => 'holiday']], ['invalid date']];
        yield 'date in another format' => [[['date' => '03/11/2026', 'kind' => 'holiday']], ['invalid date']];
        yield 'impossible date' => [[['date' => '2026-02-30', 'kind' => 'holiday']], ['invalid date']];
        yield 'unknown kind' => [[['date' => '2026-11-03', 'kind' => 'sick']], ['invalid kind']];
        yield 'missing kind' => [[['date' => '2026-11-03']], ['invalid kind']];
        yield 'two bad entries' => [[['date' => 'x', 'kind' => 'holiday'], ['date' => 'y', 'kind' => 'holiday'], ['date' => '2026-11-03', 'kind' => 1]], ['invalid date', 'invalid kind']];
    }

    /**
     * FR-L2: one bad entry is dropped alone; the good ones are kept and one warning lists counts and reasons.
     *
     * @param array<mixed> $bad
     * @param list<string> $reasons
     */
    #[DataProvider('providerBadEntries')]
    public function testBadEntriesAreDroppedAloneWithAWarning(array $bad, array $reasons): void
    {
        $logger = new RecordingLogger();
        $good   = ['date' => '2026-11-10', 'kind' => 'holiday', 'label' => 'Kept'];

        self::assertSame([['date' => '2026-11-10', 'kind' => 'holiday', 'label' => 'Kept']], ClientDaysOffNormalizer::normalize([...$bad, $good], self::FROM, self::TO, $logger));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_me', 'dropped' => count($bad), 'reasons' => $reasons], $logger->records[0]['context']);
        self::assertNotContains('Kept', $logger->contextValues());
    }

    public function testABadLabelKeepsTheDayOffWithoutALabel(): void
    {
        $logger = new RecordingLogger();

        self::assertSame([
            ['date' => '2026-11-03', 'kind' => 'holiday', 'label' => null],
            ['date' => '2026-11-04', 'kind' => 'vacation', 'label' => null],
        ], ClientDaysOffNormalizer::normalize([
            ['date' => '2026-11-03', 'kind' => 'holiday', 'label' => 42],
            ['date' => '2026-11-04', 'kind' => 'vacation', 'label' => "\xff\xfe"],
        ], self::FROM, self::TO, $logger));
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_me', 'dropped' => 0, 'reasons' => ['invalid label']], $logger->records[0]['context']);
    }

    public function testOnlyTheFirstEntriesAreRead(): void
    {
        $logger  = new RecordingLogger();
        $entries = [...array_fill(0, ClientDaysOffNormalizer::MAX_ENTRIES, ['date' => '2026-11-03', 'kind' => 'holiday']), ['date' => '2026-11-04', 'kind' => 'holiday']];

        self::assertSame([['date' => '2026-11-03', 'kind' => 'holiday', 'label' => null]], ClientDaysOffNormalizer::normalize($entries, self::FROM, self::TO, $logger));
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_me', 'dropped' => 1, 'reasons' => ['too many entries']], $logger->records[0]['context']);
    }

    public function testANonListIsNullWithAWarning(): void
    {
        $logger = new RecordingLogger();

        self::assertNull(ClientDaysOffNormalizer::normalize(['a' => ['date' => '2026-11-03', 'kind' => 'holiday']], self::FROM, self::TO, $logger));
        self::assertSame(['bundle' => 'time_track', 'action' => 'client_me', 'dropped' => 0, 'reasons' => ['not a list']], $logger->records[0]['context']);
        self::assertNull(ClientDaysOffNormalizer::normalize(['a' => 1], self::FROM, self::TO));
    }
}
