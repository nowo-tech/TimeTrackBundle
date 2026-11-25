<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\EmptySubjectProviders;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

use function array_map;
use function mb_strlen;
use function range;
use function str_repeat;

/**
 * Since 2.1.0 (Beacon spec 256): search, activity types, allocations and patches validate their input.
 */
final class SubjectValueObjectsTest extends TestCase
{
    public function testContextKeepsActorAndScope(): void
    {
        $actor   = TimeTrackUserRef::ofId('App\\User', '7');
        $context = new TimeSubjectContext($actor, 'org-a');

        self::assertSame($actor, $context->actor);
        self::assertSame('org-a', $context->scopeKey);
    }

    public function testSearchCleansTheQueryAndKeepsTheLimit(): void
    {
        $search = new TimeSubjectSearch('project', " ac\u{200B}me ", 50);

        self::assertSame('acme', $search->query);
        self::assertSame(50, $search->limit);
        self::assertSame(TimeSubjectSearch::DEFAULT_LIMIT, (new TimeSubjectSearch('project'))->limit);
        self::assertSame(1, (new TimeSubjectSearch('project', '', 1))->limit);
    }

    public function testSearchQueryLengthIsMeasuredAfterCleaning(): void
    {
        self::assertSame(100, mb_strlen((new TimeSubjectSearch('project', '  ' . str_repeat('é', 100) . "\u{200B}  "))->query));
        $this->expectException(InvalidArgumentException::class);
        new TimeSubjectSearch('project', str_repeat('a', 101));
    }

    public function testSearchRefusesABadType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeSubjectSearch('Project');
    }

    public function testSearchRefusesALimitBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeSubjectSearch('project', '', 0);
    }

    public function testSearchRefusesALimitAboveFifty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeSubjectSearch('project', '', 51);
    }

    public function testActivityTypeIsCleaned(): void
    {
        $type = new ActivityType('dev.backend-1', " Develop\u{202E}ment ", true, false, ' Eng ');

        self::assertSame('Development', $type->label);
        self::assertSame('Eng', $type->group);
        self::assertTrue($type->defaultBillable);
        self::assertFalse($type->active);
        self::assertSame(['key' => 'dev.backend-1', 'label' => 'Development', 'group' => 'Eng', 'active' => false], $type->toArray());
        self::assertNull((new ActivityType('dev', 'Dev'))->group);
        self::assertNull((new ActivityType('dev', 'Dev', null, true, ' '))->group);
    }

    public function testActivityTypeKeyRules(): void
    {
        self::assertTrue(ActivityType::isKey('a' . str_repeat('b', 63)));
        self::assertFalse(ActivityType::isKey('a' . str_repeat('b', 64)));
        self::assertFalse(ActivityType::isKey('Dev'));
        self::assertFalse(ActivityType::isKey('_dev'));
        self::assertFalse(ActivityType::isKey("dev\n"));
        $this->expectException(InvalidArgumentException::class);
        new ActivityType('Dev', 'Dev');
    }

    public function testActivityTypeNeedsALabel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ActivityType('dev', "\u{200B}");
    }

    public function testActivityTypeListFindsByExactKeyAndFiltersActive(): void
    {
        $list = ActivityTypeList::of([new ActivityType('dev', 'Dev'), new ActivityType('old', 'Old', null, false)]);

        self::assertSame('Dev', $list->find('dev')?->label);
        self::assertNull($list->find('DEV'));
        self::assertNull($list->find('none'));
        self::assertSame(['dev'], array_map(static fn (ActivityType $type): string => $type->key, $list->active()));
        self::assertCount(2, $list->all());
        self::assertSame([], ActivityTypeList::empty()->all());
    }

    public function testActivityTypeListRefusesDuplicates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActivityTypeList::of([new ActivityType('dev', 'Dev'), new ActivityType('dev', 'Dev again')]);
    }

    public function testActivityTypeListHoldsAtMost500(): void
    {
        $types = array_map(static fn (int $i): ActivityType => new ActivityType('t' . $i, 'T'), range(1, 500));
        self::assertCount(500, ActivityTypeList::of($types)->all());

        $types[] = new ActivityType('t501', 'T');
        $this->expectException(InvalidArgumentException::class);
        ActivityTypeList::of($types);
    }

    public function testAllocationFactories(): void
    {
        $subject = new TimeSubjectRef('project', 'p-1');

        $task = TimeAllocation::task('t-1', $subject, 'dev');
        self::assertSame('t-1', $task->taskId);
        self::assertSame($subject, $task->subject);
        self::assertSame('dev', $task->activityTypeKey);
        self::assertTrue($task->isAllocated());

        $onlySubject = TimeAllocation::subject($subject);
        self::assertNull($onlySubject->taskId);
        self::assertTrue($onlySubject->isAllocated());

        $none = TimeAllocation::none('dev');
        self::assertFalse($none->isAllocated());
        self::assertSame('dev', $none->activityTypeKey);

        self::assertFalse(TimeAllocation::ofTask(null)->isAllocated());
        self::assertSame('t-2', TimeAllocation::ofTask('t-2')->taskId);
    }

    public function testAllocationRefusesAnEmptyTask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeAllocation::task('');
    }

    public function testAllocationRefusesABadActivityKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeAllocation::none('Bad Key');
    }

    public function testPatchKeepIsTriStateKeep(): void
    {
        $current = new TimeSubjectRef('project', 'p-1');
        $keep    = TimeAllocationPatch::keep();

        self::assertTrue($keep->isKeep());
        self::assertSame($current, $keep->subjectAfter($current));
        self::assertSame('dev', $keep->activityTypeAfter('dev'));
        self::assertFalse($keep->changesSubjectOf($current));
        self::assertFalse($keep->changesActivityTypeOf('dev'));
    }

    public function testPatchSetAndClear(): void
    {
        $current = new TimeSubjectRef('project', 'p-1');
        $next    = new TimeSubjectRef('project', 'p-2');

        $set = TimeAllocationPatch::keep()->withSubject($next)->withActivityType('qa');
        self::assertFalse($set->isKeep());
        self::assertSame($next, $set->subjectAfter($current));
        self::assertSame('qa', $set->activityTypeAfter('dev'));
        self::assertTrue($set->changesSubjectOf($current));
        self::assertFalse($set->changesSubjectOf(new TimeSubjectRef('project', 'p-2')));
        self::assertTrue($set->changesActivityTypeOf('dev'));
        self::assertFalse($set->changesActivityTypeOf('qa'));

        $clear = TimeAllocationPatch::keep()->withoutActivityType()->withoutSubject();
        self::assertFalse($clear->isKeep());
        self::assertNull($clear->subjectAfter($current));
        self::assertNull($clear->activityTypeAfter('dev'));
        self::assertTrue($clear->changesSubjectOf($current));
        self::assertFalse($clear->changesSubjectOf(null));
        self::assertTrue($clear->changesActivityTypeOf('dev'));
        self::assertFalse($clear->changesActivityTypeOf(null));

        $onlySubject = TimeAllocationPatch::keep()->withSubject($next);
        self::assertSame('dev', $onlySubject->activityTypeAfter('dev'));
        $onlyActivity = TimeAllocationPatch::keep()->withActivityType('qa');
        self::assertSame($current, $onlyActivity->subjectAfter($current));
    }

    public function testPatchRefusesABadActivityKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TimeAllocationPatch::keep()->withActivityType('Bad');
    }

    public function testEmptyProvidersHaveNothing(): void
    {
        $providers = new EmptySubjectProviders();

        self::assertFalse($providers->has('project'));
        $this->expectException(NotFoundExceptionInterface::class);
        $providers->get('project');
    }
}
