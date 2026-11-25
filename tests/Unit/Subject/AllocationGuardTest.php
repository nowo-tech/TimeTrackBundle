<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeCatalog;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;
use Nowo\TimeTrackBundle\Subject\AllocationGuard;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\SpyTimeSubjectProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Since 2.1.0 (Beacon spec 256): every branch of the allocation rules.
 */
final class AllocationGuardTest extends TestCase
{
    private SpyTimeSubjectProvider $projects;

    private TestUser $owner;

    protected function setUp(): void
    {
        $this->projects = new SpyTimeSubjectProvider('project', [], ['p-1']);
        $this->owner    = new TestUser('7', 'ana');
    }

    public function testOffHasNothing(): void
    {
        $guard = AllocationGuard::off();

        self::assertFalse($guard->subjects()->isEnabled());
        self::assertFalse($guard->activityTypes()->isEnabled());
        self::assertFalse($guard->requiresAllocation());
        self::assertFalse($guard->requiresActivityType());
        self::assertNull($guard->missingRequirement(false, null, null));
    }

    public function testRequiresActivityTypeOnlyWithACatalog(): void
    {
        self::assertFalse((new AllocationGuard(TimeSubjectRegistry::none(), new ActivityTypeCatalog(), false, true))->requiresActivityType());
        self::assertTrue($this->guard(requireActivityType: true)->requiresActivityType());
        self::assertTrue($this->guard(requireAllocation: true)->requiresAllocation());
    }

    public function testAnUnknownTypeIsForbidden(): void
    {
        $this->expectCode(TimeAllocationException::SUBJECT_FORBIDDEN);
        $this->guard()->assertSubject($this->owner, 'org-a', new TimeSubjectRef('client', 'c-1'), false);
    }

    public function testASubjectTheOwnerMayNotLogIsForbidden(): void
    {
        $this->expectCode(TimeAllocationException::SUBJECT_FORBIDDEN);
        $this->guard()->assertSubject($this->owner, null, new TimeSubjectRef('project', 'p-2'), false);
    }

    public function testASubjectThatRefusesTasksConflictsWithATask(): void
    {
        $this->expectCode(TimeAllocationException::ALLOCATION_CONFLICT);
        $this->guard(allowsTask: false)->assertSubject($this->owner, 'org-a', new TimeSubjectRef('project', 'p-1'), true);
    }

    public function testALoggableSubjectPasses(): void
    {
        $this->guard(allowsTask: false)->assertSubject($this->owner, 'org-a', new TimeSubjectRef('project', 'p-1'), false);
        $this->guard()->assertSubject($this->owner, 'org-a', new TimeSubjectRef('project', 'p-1'), true);
        self::assertSame(2, $this->projects->canLogCalls);
    }

    public function testAssertAllowsTask(): void
    {
        $guard   = $this->guard(allowsTask: false);
        $subject = new TimeSubjectRef('project', 'p-1');
        $guard->assertAllowsTask(null, true);
        $guard->assertAllowsTask($subject, false);
        $this->guard()->assertAllowsTask($subject, true);

        $this->expectCode(TimeAllocationException::ALLOCATION_CONFLICT);
        $guard->assertAllowsTask($subject, true);
    }

    public function testActivityTypeWithoutCatalogIsUnknown(): void
    {
        $this->expectCode(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN);
        (new AllocationGuard(TimeSubjectRegistry::none(), new ActivityTypeCatalog()))->activityType('org-a', 'dev');
    }

    public function testAnUnknownActivityTypeIsRefused(): void
    {
        $this->expectCode(TimeAllocationException::ACTIVITY_TYPE_UNKNOWN);
        $this->guard()->activityType('org-a', 'nope');
    }

    public function testAnInactiveActivityTypeIsRefused(): void
    {
        $this->expectCode(TimeAllocationException::ACTIVITY_TYPE_INACTIVE);
        $this->guard()->activityType('org-a', 'old');
    }

    public function testAnActiveActivityTypeIsAnswered(): void
    {
        self::assertSame('Dev', $this->guard()->activityType(null, 'dev')->label);
        self::assertSame('Given', $this->guard()->activityType('org-a', 'dev', ActivityTypeList::of([new ActivityType('dev', 'Given')]))->label);
    }

    public function testCheckNewChecksSubjectActivityAndRequirements(): void
    {
        $guard = $this->guard(requireAllocation: true, requireActivityType: true);

        self::assertSame('dev', $guard->checkNew($this->owner, 'org-a', TimeAllocation::subject(new TimeSubjectRef('project', 'p-1'), 'dev'), true)?->key);
        self::assertNull($guard->checkNew($this->owner, 'org-a', TimeAllocation::none(), false), 'non-interactive writes skip the requirements');
        self::assertNull($this->guard()->checkNew($this->owner, 'org-a', TimeAllocation::task('t-1'), true));
    }

    public function testCheckNewRefusesTheSubjectFirst(): void
    {
        $this->expectCode(TimeAllocationException::ALLOCATION_CONFLICT);
        $this->guard(allowsTask: false)->checkNew($this->owner, 'org-a', TimeAllocation::task('t-1', new TimeSubjectRef('project', 'p-1')), false);
    }

    public function testCheckNewRefusesAMissingAllocation(): void
    {
        $this->expectCode(TimeAllocationException::ALLOCATION_REQUIRED);
        $this->guard(requireAllocation: true)->checkNew($this->owner, 'org-a', TimeAllocation::none('dev'), true);
    }

    public function testCheckNewRefusesAMissingActivityType(): void
    {
        $this->expectCode(TimeAllocationException::ACTIVITY_TYPE_REQUIRED);
        $this->guard(requireActivityType: true)->checkNew($this->owner, 'org-a', TimeAllocation::task('t-1'), true);
    }

    public function testMissingRequirement(): void
    {
        $guard   = $this->guard(requireAllocation: true, requireActivityType: true);
        $subject = new TimeSubjectRef('project', 'p-1');

        self::assertSame(TimeAllocationException::ALLOCATION_REQUIRED, $guard->missingRequirement(false, null, 'dev'));
        self::assertSame(TimeAllocationException::ACTIVITY_TYPE_REQUIRED, $guard->missingRequirement(true, null, null));
        self::assertSame(TimeAllocationException::ACTIVITY_TYPE_REQUIRED, $guard->missingRequirement(false, $subject, null));
        self::assertNull($guard->missingRequirement(false, $subject, 'dev'));
        $guard->assertRequirements(true, null, 'dev');
    }

    public function testMayClearSubject(): void
    {
        $subject = new TimeSubjectRef('project', 'p-1');
        $open    = $this->guard(allowWithoutTask: true);

        $open->assertMayClearSubject(false, $subject, null);
        $open->assertMayClearSubject(false, null, null);
        $open->assertMayClearSubject(true, $subject, null);
        $this->guard()->assertMayClearSubject(true, $subject, null);
        $this->guard()->assertMayClearSubject(false, $subject, new TimeSubjectRef('project', 'p-2'));

        foreach ([$this->guard(), $this->guard(requireAllocation: true, allowWithoutTask: true)] as $guard) {
            try {
                $guard->assertMayClearSubject(false, $subject, null);
                self::fail('cleared the only allocation');
            } catch (TimeAllocationException $exception) {
                self::assertSame(TimeAllocationException::ALLOCATION_REQUIRED, $exception->errorCode);
            }
        }
    }

    public function testBillableDefaultPrecedence(): void
    {
        $host = static fn (): bool => true;

        self::assertFalse(AllocationGuard::billableDefault(new ActivityType('x', 'X', false), $host));
        self::assertTrue(AllocationGuard::billableDefault(new ActivityType('x', 'X', true), static fn (): bool => false));
        self::assertTrue(AllocationGuard::billableDefault(new ActivityType('x', 'X'), $host));
        self::assertTrue(AllocationGuard::billableDefault(null, $host));
        self::assertNull(AllocationGuard::billableDefault(null, static fn (): ?bool => null));
    }

    /**
     * A guard with the project provider and a dev/old catalog.
     */
    private function guard(bool $requireAllocation = false, bool $requireActivityType = false, bool $allowWithoutTask = false, bool $allowsTask = true): AllocationGuard
    {
        $projects = $this->projects;

        return new AllocationGuard(
            new TimeSubjectRegistry(new ServiceLocator(['project' => static fn (): SpyTimeSubjectProvider => $projects]), ['project' => $allowsTask]),
            new ActivityTypeCatalog(new class implements ActivityTypeProviderInterface {
                /**
                 * Dev (active) and old (inactive).
                 */
                public function activityTypesFor(string $scopeKey): ActivityTypeList
                {
                    return ActivityTypeList::of([new ActivityType('dev', 'Dev'), new ActivityType('old', 'Old', null, false)]);
                }
            }),
            $requireAllocation,
            $requireActivityType,
            $allowWithoutTask,
        );
    }

    /**
     * Expects a TimeAllocationException with the code.
     */
    private function expectCode(string $code): void
    {
        $this->expectException(TimeAllocationException::class);
        $this->expectExceptionMessage($code);
    }
}
