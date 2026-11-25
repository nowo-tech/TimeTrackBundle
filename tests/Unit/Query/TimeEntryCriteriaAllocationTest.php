<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Query;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Query\AllocationFilter;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_keys;

/**
 * Since 2.1.0 (Beacon spec 256): subject, subject type, activity type and allocation filters.
 */
final class TimeEntryCriteriaAllocationTest extends TestCase
{
    public function testDefaultsMatchAnyAllocation(): void
    {
        $criteria = new TimeEntryCriteria(ownerIds: ['7']);

        self::assertNull($criteria->subjects);
        self::assertNull($criteria->subjectTypes);
        self::assertNull($criteria->activityTypeKeys);
        self::assertSame(AllocationFilter::Any, $criteria->allocation);
        self::assertNull($criteria->subjectIdsByType());
        self::assertTrue($criteria->matchesAllocation(false, null, null));
        self::assertTrue($criteria->matchesExactly('e1', '7', null, '', null));
    }

    public function testASubjectFilterAloneIsNotAKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCriteria(subjects: [new TimeSubjectRef('client', 'c-1')]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidFilters')]
    public function testInvalidValuesThrow(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeEntryCriteria(...(['ownerIds' => ['7']] + $arguments));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidFilters(): iterable
    {
        yield 'subject not a ref' => [['subjects' => [['type' => 'client', 'id' => 'c-1']]]];
        yield 'too many subjects' => [['subjects' => array_fill(0, TimeEntryCriteria::MAX_VALUES + 1, new TimeSubjectRef('client', 'c-1'))]];
        yield 'bad subject type' => [['subjectTypes' => ['Client']]];
        yield 'empty subject type' => [['subjectTypes' => ['']]];
        yield 'subject type not a string' => [['subjectTypes' => [7]]];
        yield 'too many subject types' => [['subjectTypes' => array_fill(0, TimeEntryCriteria::MAX_VALUES + 1, 'client')]];
        yield 'bad activity key' => [['activityTypeKeys' => ['Dev ']]];
        yield 'empty activity key' => [['activityTypeKeys' => ['']]];
    }

    public function testEmptyListsMatchNothing(): void
    {
        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], subjects: []))->matchesNothing());
        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], subjectTypes: []))->matchesNothing());
        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], activityTypeKeys: []))->matchesNothing());
        self::assertFalse((new TimeEntryCriteria(ownerIds: ['7'], subjectTypes: ['client']))->matchesNothing());
    }

    public function testSubjectFilterMatchesTaskAndSubjectAloneEntriesExactly(): void
    {
        $c1 = new TimeSubjectRef('client', 'c-1');
        /* @phpstan-ignore argument.type (a keyed array is normalised to a list) */
        $criteria = new TimeEntryCriteria(ownerIds: ['7'], subjects: ['x' => $c1, 'y' => new TimeSubjectRef('client', 'c-2'), 'z' => new TimeSubjectRef('project', 'p-1')]);

        self::assertEquals([$c1, new TimeSubjectRef('client', 'c-2'), new TimeSubjectRef('project', 'p-1')], $criteria->subjects);
        self::assertSame([0, 1, 2], array_keys($criteria->subjects ?? []));
        self::assertSame(['client' => ['c-1', 'c-2'], 'project' => ['p-1']], $criteria->subjectIdsByType());
        self::assertTrue($criteria->matchesExactly('e1', '7', null, 't-1', null, new TimeSubjectRef('client', 'c-1')));
        self::assertTrue($criteria->matchesExactly('e1', '7', null, '', null, $c1));
        self::assertFalse($criteria->matchesExactly('e1', '7', null, '', null, new TimeSubjectRef('client', 'C-1')), 'byte for byte');
        self::assertFalse($criteria->matchesExactly('e1', '7', null, '', null, new TimeSubjectRef('project', 'c-1')));
        self::assertFalse($criteria->matchesExactly('e1', '7', null, 't-1', null));
    }

    public function testSubjectTypeAndActivityFilters(): void
    {
        $criteria = new TimeEntryCriteria(ownerIds: ['7'], subjectTypes: ['client'], activityTypeKeys: ['dev']);

        self::assertSame(['client'], $criteria->subjectTypes);
        self::assertSame(['dev'], $criteria->activityTypeKeys);
        self::assertTrue($criteria->matchesAllocation(true, new TimeSubjectRef('client', 'c-9'), 'dev'));
        self::assertFalse($criteria->matchesAllocation(true, null, 'dev'), 'no subject never matches a type filter');
        self::assertFalse($criteria->matchesAllocation(true, new TimeSubjectRef('project', 'c-9'), 'dev'));
        self::assertFalse($criteria->matchesAllocation(true, new TimeSubjectRef('client', 'c-9'), null));
        self::assertFalse($criteria->matchesAllocation(true, new TimeSubjectRef('client', 'c-9'), 'qa'));
    }

    public function testAllocationFilter(): void
    {
        $subject = new TimeSubjectRef('client', 'c-1');

        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], allocation: AllocationFilter::Unallocated))->matchesExactly('e1', '7', null, '', null));
        self::assertFalse((new TimeEntryCriteria(ownerIds: ['7'], allocation: AllocationFilter::Unallocated))->matchesExactly('e1', '7', null, 't-1', null));
        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], allocation: AllocationFilter::SubjectOnly))->matchesAllocation(false, $subject, null));
        self::assertFalse((new TimeEntryCriteria(ownerIds: ['7'], allocation: AllocationFilter::SubjectOnly))->matchesAllocation(true, $subject, null));
        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], allocation: AllocationFilter::TaskAndSubject))->matchesAllocation(true, $subject, null));
        self::assertTrue((new TimeEntryCriteria(ownerIds: ['7'], allocation: AllocationFilter::TaskOnly))->matchesAllocation(true, null, null));
    }

    public function testAllocationFilterEnum(): void
    {
        self::assertSame(AllocationFilter::TaskAndSubject, AllocationFilter::of(true, true));
        self::assertSame(AllocationFilter::TaskOnly, AllocationFilter::of(true, false));
        self::assertSame(AllocationFilter::SubjectOnly, AllocationFilter::of(false, true));
        self::assertSame(AllocationFilter::Unallocated, AllocationFilter::of(false, false));
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$task, $subject]) {
            self::assertTrue(AllocationFilter::Any->matches($task, $subject));
        }
        self::assertFalse(AllocationFilter::TaskOnly->matches(true, true));
        self::assertSame(AllocationFilter::Unallocated, AllocationFilter::from('none'));
        self::assertSame(AllocationFilter::TaskAndSubject, AllocationFilter::from('task_subject'));
    }
}
