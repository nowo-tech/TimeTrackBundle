<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function str_repeat;

/**
 * 1.35.0: the filters of the entry read port are keyed, bounded and compared byte for byte.
 */
final class TimeEntryCriteriaTest extends TestCase
{
    public function testAQueryNeedsAKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCriteria(startedFrom: new DateTimeImmutable());
    }

    public function testAnOverlapNeedsBothEnds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCriteria(ownerIds: ['7'], overlapFrom: new DateTimeImmutable());
    }

    public function testAListIsBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCriteria(taskIds: array_fill(0, TimeEntryCriteria::MAX_VALUES + 1, 'task-1'));
    }

    public function testSourcesMustBeCases(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /* @phpstan-ignore argument.type (the wrong type is the point of the test) */
        new TimeEntryCriteria(ownerIds: ['7'], sources: ['web']);
    }

    public function testTooManySourcesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCriteria(ownerIds: ['7'], sources: array_fill(0, TimeEntryCriteria::MAX_VALUES + 1, TimeEntrySource::Web));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('unusableValues')]
    public function testUnusableValuesAreRefusedNeverTrimmed(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCriteria(...$arguments);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableValues(): iterable
    {
        yield 'blank owner' => [['ownerIds' => ['7 ']]];
        yield 'empty owner' => [['ownerIds' => ['']]];
        yield 'not a string' => [['ownerIds' => [7]]];
        yield 'invisible owner' => [['ownerIds' => ["7\u{200B}"]]];
        yield 'empty scope' => [['scopeKeys' => ['']]];
        yield 'scope with a blank' => [['scopeKeys' => [' org-a']]];
        yield 'long task id' => [['taskIds' => [str_repeat('t', 37)]]];
        yield 'board with a blank' => [['boardIds' => ['board-1 ']]];
        yield 'excluded entry' => [['ownerIds' => ['7'], 'excludeEntryIds' => ['']]];
    }

    public function testEmptyListsMatchNothing(): void
    {
        self::assertFalse(TimeEntryCriteria::ofOwner('7')->matchesNothing());
        self::assertTrue((new TimeEntryCriteria(ownerIds: []))->matchesNothing());
        self::assertTrue((new TimeEntryCriteria(scopeKeys: []))->matchesNothing());
        self::assertTrue((new TimeEntryCriteria(taskIds: []))->matchesNothing());
        self::assertTrue((new TimeEntryCriteria(boardIds: []))->matchesNothing());
        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], sources: []))->matchesNothing());
    }

    public function testRowsAreMatchedByteForByte(): void
    {
        $criteria = new TimeEntryCriteria(ownerIds: ['abc'], scopeKeys: ['org-a'], taskIds: ['task-1'], boardIds: ['board-1'], sources: [TimeEntrySource::Web], excludeEntryIds: ['e-2']);
        self::assertSame([TimeEntrySource::Web], $criteria->sources);
        self::assertTrue($criteria->matchesExactly('e-1', 'abc', 'org-a', 'task-1', 'board-1'));
        self::assertFalse($criteria->matchesExactly('e-1', 'ABC', 'org-a', 'task-1', 'board-1'), 'owner case');
        self::assertFalse($criteria->matchesExactly('e-1', null, 'org-a', 'task-1', 'board-1'), 'no owner');
        self::assertFalse($criteria->matchesExactly('e-1', 'abc', 'ORG-A', 'task-1', 'board-1'), 'scope case');
        self::assertFalse($criteria->matchesExactly('e-1', 'abc', null, 'task-1', 'board-1'), 'no scope');
        self::assertFalse($criteria->matchesExactly('e-1', 'abc', 'org-a', 'TASK-1', 'board-1'), 'task case');
        self::assertFalse($criteria->matchesExactly('e-1', 'abc', 'org-a', 'task-1', null), 'no board');
        self::assertFalse($criteria->matchesExactly('e-1', 'abc', 'org-a', 'task-1', 'BOARD-1'), 'board case');
        self::assertFalse($criteria->matchesExactly('e-2', 'abc', 'org-a', 'task-1', 'board-1'), 'excluded');

        $any = new TimeEntryCriteria(taskIds: ['task-1']);
        self::assertTrue($any->matchesExactly('e-1', '9', null, 'task-1', null));
    }
}
