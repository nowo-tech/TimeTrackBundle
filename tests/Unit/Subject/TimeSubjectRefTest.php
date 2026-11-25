<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Since 2.1.0 (Beacon spec 256): a subject reference is a strict (type, id) pair, never trimmed.
 */
final class TimeSubjectRefTest extends TestCase
{
    public function testAValidPairIsKept(): void
    {
        $ref = new TimeSubjectRef('project', 'p-1');

        self::assertSame('project', $ref->type);
        self::assertSame('p-1', $ref->id);
        self::assertSame("project\0p-1", $ref->key());
        self::assertSame(['type' => 'project', 'id' => 'p-1'], $ref->toArray());
    }

    /**
     * @param string $type The refused type
     */
    #[DataProvider('badTypes')]
    public function testABadTypeIsRefused(string $type): void
    {
        self::assertFalse(TimeSubjectRef::isType($type));
        $this->expectException(InvalidArgumentException::class);
        new TimeSubjectRef($type, 'p-1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Project'];
        yield 'leading digit' => ['1project'];
        yield 'dash' => ['my-project'];
        yield 'too long' => ['a' . str_repeat('b', 32)];
        yield 'trailing newline' => ["project\n"];
    }

    /**
     * @param string $id The refused id
     */
    #[DataProvider('badIds')]
    public function testABadIdIsRefused(string $id): void
    {
        self::assertFalse(TimeSubjectRef::isId($id));
        $this->expectException(InvalidArgumentException::class);
        new TimeSubjectRef('project', $id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('x', 65)];
        yield 'edge blank' => [' p-1'];
        yield 'invisible' => ["p\u{200B}1"];
    }

    public function testLimitsAreInclusive(): void
    {
        self::assertTrue(TimeSubjectRef::isType('a' . str_repeat('b', 31)));
        self::assertTrue(TimeSubjectRef::isId(str_repeat('x', 64)));
    }

    public function testFromColumnsNeedsBothOrNone(): void
    {
        self::assertNull(TimeSubjectRef::fromColumns(null, null));
        self::assertTrue((new TimeSubjectRef('project', 'p-1'))->equals(TimeSubjectRef::fromColumns('project', 'p-1') ?? self::fail()));

        try {
            TimeSubjectRef::fromColumns('project', null);
            self::fail('half pair accepted');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        TimeSubjectRef::fromColumns(null, 'p-1');
    }

    public function testTryFromColumnsAnswersNullForAnythingMalformed(): void
    {
        self::assertNull(TimeSubjectRef::tryFromColumns(null, 'p-1'));
        self::assertNull(TimeSubjectRef::tryFromColumns('project', 7));
        self::assertNull(TimeSubjectRef::tryFromColumns('Project', 'p-1'));
        self::assertNull(TimeSubjectRef::tryFromColumns('project', ''));
        self::assertSame('p-1', TimeSubjectRef::tryFromColumns('project', 'p-1')?->id);
    }

    public function testEqualityIsExact(): void
    {
        $a = new TimeSubjectRef('project', 'p-1');

        self::assertTrue($a->equals(new TimeSubjectRef('project', 'p-1')));
        self::assertFalse($a->equals(new TimeSubjectRef('project', 'P-1')));
        self::assertFalse($a->equals(new TimeSubjectRef('client', 'p-1')));
        self::assertTrue(TimeSubjectRef::same(null, null));
        self::assertFalse(TimeSubjectRef::same(null, $a));
        self::assertFalse(TimeSubjectRef::same($a, null));
        self::assertTrue(TimeSubjectRef::same($a, new TimeSubjectRef('project', 'p-1')));
    }
}
