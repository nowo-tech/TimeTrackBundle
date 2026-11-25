<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 2.1.0 (Beacon spec 256, design §7.3, Q-TS-2) with `entries.require_allocation` on the Doctrine stores: an
 * interactive stop of unallocated time is refused inside the unit of work (the timer row stays, no entry row is written),
 * a manual entry without an allocation is refused, and a correction may not leave a subject entry unallocated.
 */
final class AllocationRequiredDatabaseTest extends FunctionalTestCase
{
    private CustomerTestSubjectProvider $customers;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return [
            'manual_entries' => ['enabled' => true],
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'entries'        => ['allow_without_task' => true, 'require_allocation' => true],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a']];
        $customers           = self::getContainer()->get(CustomerTestSubjectProvider::class);
        self::assertInstanceOf(CustomerTestSubjectProvider::class, $customers);
        $customers->reset();
        $customers->loggable = ['c-1'];
        $this->customers     = $customers;
    }

    public function testAnInteractiveStopOfUnallocatedTimeLeavesTheTimerRowAndWritesNoEntry(): void
    {
        $api = self::getContainer()->get(HostTimerApi::class);
        self::assertInstanceOf(HostTimerApi::class, $api);
        $actor = TimeTrackUserRefFactory::build($this->user);
        $timer = $api->startAllocated($actor, TimeAllocation::none('dev'), 'note')->timer;

        try {
            $api->stop($actor, 'done');
            self::fail('expected allocation_required');
        } catch (TimeAllocationRequiredException $exception) {
            self::assertSame([TimeAllocationException::ALLOCATION_REQUIRED, $timer->timerId], [$exception->errorCode, $exception->timer->timerId]);
        }
        $this->clearEntityManagers();
        $connection = $this->entityManager()->getConnection();
        self::assertSame([1, 0], [(int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'), (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_entries')]);
        self::assertSame($timer->timerId, $api->current($actor)?->timerId);

        $entry = $api->stop($actor, 'done', TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-1')));
        self::assertSame(['c-1', 'dev', 'org-a'], [$entry?->subject?->id, $entry?->activityTypeKey, $entry?->scopeKey]);
        self::assertSame([0, 1], [(int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'), (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_entries')]);
    }

    public function testManualEntriesAndCorrectionsMayNotLeaveTimeUnallocated(): void
    {
        $operations = self::getContainer()->get(TimeEntryOperationsInterface::class);
        self::assertInstanceOf(TimeEntryOperationsInterface::class, $operations);
        $owner = (string) $this->user->getId();
        $start = new DateTimeImmutable('today -1 day 09:00');

        try {
            $operations->createManual($owner, new ManualTimeEntryDraft(null, $start, $start->modify('+1 hour')));
            self::fail('expected allocation_required');
        } catch (TimeAllocationException $exception) {
            self::assertSame(TimeAllocationException::ALLOCATION_REQUIRED, $exception->errorCode);
        }

        $manual = self::getContainer()->get(ManualEntryService::class);
        self::assertInstanceOf(ManualEntryService::class, $manual);
        $id    = $manual->create($this->user, TimeAllocation::subject(new TimeSubjectRef('customer', 'c-1')), $start, $start->modify('+1 hour'), TimeEntrySource::Manual)->getId();
        $query = self::getContainer()->get(TimeEntryQueryInterface::class);
        self::assertInstanceOf(TimeEntryQueryInterface::class, $query);
        $version = $query->find($id)?->version() ?? self::fail('readable');
        try {
            $operations->correct($owner, $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withoutSubject()));
            self::fail('expected allocation_required');
        } catch (TimeAllocationException $exception) {
            self::assertSame(TimeAllocationException::ALLOCATION_REQUIRED, $exception->errorCode);
        }
        self::assertSame($version, $query->find($id)->version(), 'nothing written');
        self::assertCount(1, $this->customers->canLogCalls, 'only the create asked canLog: clearing never does');
    }
}
