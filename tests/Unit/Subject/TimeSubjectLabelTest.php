<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function mb_strlen;
use function str_repeat;

/**
 * Since 2.1.0 (Beacon spec 256): provider labels are cleaned and cut; only absolute https URLs without credentials pass.
 */
final class TimeSubjectLabelTest extends TestCase
{
    public function testControlBidiAndInvisibleCharactersAreCleaned(): void
    {
        $label = new TimeSubjectLabel(new TimeSubjectRef('project', 'p-1'), "  Pro\u{202E}ject\u{200B}\tA\x07 ", null, " hint\u{2066} ");

        self::assertSame('Project A', $label->label);
        self::assertSame('hint', $label->hint);
        self::assertNull($label->url);
    }

    public function testALongLabelIsCutAt255Characters(): void
    {
        $label = new TimeSubjectLabel(new TimeSubjectRef('project', 'p-1'), str_repeat('é', 300));

        self::assertSame(TimeSubjectLabel::MAX_LENGTH, mb_strlen($label->label));
    }

    public function testAnEmptyLabelAfterCleaningIsRefused(): void
    {
        self::assertNull(TimeSubjectLabel::clean(" \u{200B}\u{202E} "));
        $this->expectException(InvalidArgumentException::class);
        new TimeSubjectLabel(new TimeSubjectRef('project', 'p-1'), "\u{200B} ");
    }

    public function testAnEmptyHintBecomesNull(): void
    {
        self::assertNull((new TimeSubjectLabel(new TimeSubjectRef('project', 'p-1'), 'A', null, ' '))->hint);
    }

    public function testASafeHttpsUrlIsKept(): void
    {
        $url = 'https://crm.example.test/projects/p-1?tab=time#top';

        self::assertSame($url, (new TimeSubjectLabel(new TimeSubjectRef('project', 'p-1'), 'A', $url))->url);
        self::assertSame('HTTPS://crm.example.test/', TimeSubjectLabel::safeUrl('HTTPS://crm.example.test/'));
    }

    /**
     * @param string|null $url The dropped URL
     */
    #[DataProvider('unsafeUrls')]
    public function testAnUnsafeUrlIsDropped(?string $url): void
    {
        self::assertNull(TimeSubjectLabel::safeUrl($url));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'http' => ['http://crm.example.test/'];
        yield 'relative' => ['/projects/p-1'];
        yield 'protocol relative' => ['//crm.example.test/'];
        yield 'credentials' => ['https://user:pass@crm.example.test/'];
        yield 'user only' => ['https://user@crm.example.test/'];
        yield 'space' => ['https://crm.example.test/a b'];
        yield 'nbsp' => ["https://crm.example.test/a\u{00A0}b"];
        yield 'newline' => ["https://crm.example.test/\n"];
        yield 'del' => ["https://crm.example.test/\x7F"];
        yield 'no host' => ['https:///path'];
        yield 'unparsable' => ['https://:80'];
        yield 'too long' => ['https://crm.example.test/' . str_repeat('a', TimeSubjectLabel::URL_MAX_LENGTH)];
    }
}
