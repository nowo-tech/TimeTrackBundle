<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Support;

use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\TimeEntryCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function mb_strlen;
use function str_repeat;

/**
 * 1.10.2: one sanitiser and merge rule for every note (EF2-I2, EF2-I4) and the CSV formula guard (EF2-I3).
 */
final class NoteSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notes(): iterable
    {
        yield 'plain' => ['Standup', 'Standup'];
        yield 'control runs fold to one space' => ["a\0\1\e[31m\r\n\tb\x7f\u{85}c", 'a [31m b c'];
        yield 'trimmed' => ["  \t note \n", 'note'];
        yield 'bidi override removed' => ["invoice \u{202E}gpj.exe", 'invoice gpj.exe'];
        yield 'every bidi control removed' => ["\u{202A}a\u{202B}b\u{202C}c\u{202D}d\u{2066}e\u{2067}f\u{2068}g\u{2069}h", 'abcdefgh'];
        yield 'zero-width space and BOM removed everywhere' => ["\u{FEFF}\u{200B}a\u{200B}b\u{FEFF}c\u{200B}", 'abc'];
        yield 'joiners trimmed at the ends, kept inside' => ["\u{200D}\u{2060}👩\u{200D}💻\u{200C}", "👩\u{200D}💻"];
        yield 'unicode spaces trimmed' => ["\u{00A0}\u{2003}note\u{3000}", 'note'];
        yield 'only invisible' => ["\u{200B}\u{FEFF}\u{202E} \n", ''];
        yield 'invalid UTF-8' => ["bad \xC3\x28", ''];
    }

    #[DataProvider('notes')]
    public function testClean(string $raw, string $expected): void
    {
        self::assertSame($expected, NoteSanitizer::clean($raw));
    }

    public function testCleanAndCut(): void
    {
        self::assertNull(NoteSanitizer::cleanAndCut(" \u{200B} "));
        self::assertSame(str_repeat('é', 500), NoteSanitizer::cleanAndCut(str_repeat('é', 501)));
        self::assertSame(500, mb_strlen((string) NoteSanitizer::cleanAndCut(str_repeat('😀', 600))), 'code points, not bytes');
    }

    public function testMerge(): void
    {
        self::assertSame('b', NoteSanitizer::merge(null, 'b'));
        self::assertSame('b', NoteSanitizer::merge('', 'b'));
        self::assertSame("a\nb", NoteSanitizer::merge('a', 'b'));
        self::assertSame(str_repeat('a', 498) . "\nb", NoteSanitizer::merge(str_repeat('a', 498), 'bcd'));
        self::assertSame(str_repeat('a', 500), NoteSanitizer::merge(str_repeat('a', 500), 'more'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cells(): iterable
    {
        yield 'plain' => ['note', 'note'];
        yield 'empty' => ['', ''];
        yield 'equals' => ['=1+1', "'=1+1"];
        yield 'plus' => ['+1', "'+1"];
        yield 'minus' => ['-1', "'-1"];
        yield 'at' => ['@x', "'@x"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
        yield 'leading spaces' => ['  -2+3', "'  -2+3"];
        yield 'zero-width space' => ["\u{200B}=1+1", "'\u{200B}=1+1"];
        yield 'byte-order mark' => ["\u{FEFF}@SUM(A1)", "'\u{FEFF}@SUM(A1)"];
        yield 'no-break space' => ["\u{00A0}+1", "'\u{00A0}+1"];
        yield 'full-width equals' => ['＝1+1', "'＝1+1"];
        yield 'full-width plus' => ['＋1', "'＋1"];
        yield 'formula char later' => ['a=1', 'a=1'];
        yield 'only invisible' => ["\u{200B}", "\u{200B}"];
        yield 'invalid UTF-8' => ["\xC3\x28=1", "\xC3\x28=1"];
    }

    #[DataProvider('cells')]
    public function testCsvCell(string $value, string $expected): void
    {
        self::assertSame($expected, TimeEntryCsv::cell($value));
    }
}
