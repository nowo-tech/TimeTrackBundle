<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entry;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperations;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\AllocationFixtures;
use Nowo\TimeTrackBundle\Tests\Support\CorrectableInMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 2.1.0 (audit ZBQ-I5): a correction never clears the activity type of an entry while
 * `entries.require_activity_type` is effective (the option on and an activity type provider): `activity_type_required`,
 * nothing written. Without the option, or without a provider (the option then has no effect), clearing is allowed.
 */
final class TimeEntryOperationsActivityTypeTest extends TestCase
{
    private CorrectableInMemoryTimeEntryRepository $entries;

    private InMemoryActiveTimerRepository $timers;

    private MockClock $clock;

    private TestUser $ana;

    protected function setUp(): void
    {
        $this->entries = new CorrectableInMemoryTimeEntryRepository();
        $this->timers  = new InMemoryActiveTimerRepository();
        $this->clock   = new MockClock('2026-11-12 10:00:00 UTC');
        $this->ana     = new TestUser('7', 'ana');
    }

    public function testClearingTheActivityTypeUnderAnEffectiveRequirementIsRefused(): void
    {
        $entry   = $this->entry('dev');
        $version = TimeEntryRecord::fromEntry($entry)->version();
        $ops     = $this->operations(requireActivityType: true, provider: true);

        try {
            $ops->correct('7', $entry->getId(), $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withoutActivityType()));
            self::fail('expected activity_type_required');
        } catch (TimeAllocationException $exception) {
            self::assertSame(TimeAllocationException::ACTIVITY_TYPE_REQUIRED, $exception->errorCode);
        }
        self::assertSame(['dev', $version], [$entry->getActivityTypeKey(), TimeEntryRecord::fromEntry($entry)->version()], 'nothing written');

        // Changing it to another active type, or a times-only fix, stays possible.
        $changed = $ops->correct('7', $entry->getId(), $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withActivityType('support')));
        self::assertSame(['activity_type_key'], $changed->changedFields);
        $times = $ops->correct('7', $entry->getId(), $changed->after->version(), new TimeEntryCorrection(durationSeconds: 1800));
        self::assertSame(['duration_seconds'], $times->changedFields);
        self::assertSame('support', $entry->getActivityTypeKey());
    }

    public function testAnEntryWithoutAnActivityTypeKeepsItsTimesFixable(): void
    {
        $entry = $this->entry(null);
        $ops   = $this->operations(requireActivityType: true, provider: true);

        // Clearing what is not set is no change: never refused.
        $result = $ops->correct('7', $entry->getId(), TimeEntryRecord::fromEntry($entry)->version(), (new TimeEntryCorrection(durationSeconds: 1200))->withAllocation(TimeAllocationPatch::keep()->withoutActivityType()));
        self::assertSame(['duration_seconds'], $result->changedFields);
    }

    public function testClearingIsAllowedWithoutTheOptionOrWithoutAProvider(): void
    {
        foreach ([[false, true], [true, false], [false, false]] as [$require, $provider]) {
            $entry  = $this->entry('dev');
            $result = $this->operations(requireActivityType: $require, provider: $provider)->correct('7', $entry->getId(), TimeEntryRecord::fromEntry($entry)->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withoutActivityType()));
            self::assertSame([['activity_type_key'], null], [$result->changedFields, $entry->getActivityTypeKey()], $require ? 'no provider' : 'option off');
        }
    }

    private function operations(bool $requireActivityType, bool $provider): TimeEntryOperations
    {
        $tasks  = new StubTaskProvider();
        $guard  = AllocationFixtures::guard([new CustomerTestSubjectProvider()], $provider ? new FixedActivityTypeProvider() : null, requireActivityType: $requireActivityType, allowWithoutTask: true);
        $timers = new TimerService($tasks, $this->timers, $this->entries, new EventDispatcher(), $this->clock, scopeProvider: new FixedScopeProvider(['7' => ['org-a']]), allowWithoutTask: true, allocationGuard: $guard);
        $manual = new ManualEntryService($timers, $this->entries, $this->timers, new TaskProviderTaskAccessChecker($tasks), clock: $this->clock);

        return new TimeEntryOperations($this->entries, $this->timers, $manual, $this->createStub(EntityManagerInterface::class), TestUser::class, clock: $this->clock);
    }

    /**
     * A one-hour task entry on 2026-11-10 in scope org-a, with the activity type given.
     */
    private function entry(?string $activityTypeKey): TimeEntry
    {
        $startedAt = new DateTimeImmutable('2026-11-10 09:00 UTC');
        $entry     = (new TimeEntry($this->ana, 'task-1', 'Task', 'board-1', $startedAt, $startedAt->modify('+1 hour'), 3600, TimeEntrySource::Web))->allocate(null, $activityTypeKey);
        $entry->assignScopeKey('org-a');
        $this->entries->save($entry);

        return $entry;
    }
}
