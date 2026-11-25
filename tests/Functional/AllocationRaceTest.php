<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperations;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubServiceAccountPolicy;
use Nowo\TimeTrackBundle\Tests\Support\AllocationFixtures;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\HookedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\HookedCustomerSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\ServiceCopy;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 2.1.0 (Beacon spec 256, design §13, REQ-RUNTIME-003): the races of the allocation write paths, each played
 * deterministically: a hook in the host's subject or activity type provider, or in the service-account policy (all
 * asked after the write path loaded its row and before it takes the owner's lock), lets another request commit at the
 * worst moment.
 *
 * The other request runs on a **second real connection** (a second kernel booted on the same database, its own
 * entity manager and services) whenever the test database is a file or a server (`TIME_TRACK_TEST_DATABASE_URL`:
 * SQLite file, MySQL 8.4, MariaDB 11.4, PostgreSQL 16). On the default in-memory SQLite there is no second connection:
 * the other request then runs through this kernel's own services (same connection, still committed before this one
 * locks), which plays the same interleaving.
 *
 * - Double stop with different subjects: one entry, the winner's subject; the loser gets null (404 no_active_timer).
 * - Refused stop (no allocation under `require_allocation`) vs a stop with a subject: whichever locks first wins,
 *   a refused stop writes nothing and leaves the timer to the other call; no time is lost.
 * - Stop vs `allocateRunning()`: allocate first → the stop's re-read timer carries the subject; stop first → allocate
 *   finds no timer (null).
 * - Correct vs correct on the allocation: the version covers the subject, so the second correction is `conflict`.
 * - Correct vs the back-fill `UPDATE`: the back-fill changes the version, the correction is `conflict`.
 */
final class AllocationRaceTest extends FunctionalTestCase
{
    private ?KernelInterface $otherKernel = null;

    private HookedCustomerSubjectProvider $hooked;

    private HookedActivityTypeProvider $hookedTypes;

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return [
            'service_account_policy' => StubServiceAccountPolicy::class,
            'entries'                => ['allow_without_task' => true, 'require_allocation' => true],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooked           = new HookedCustomerSubjectProvider();
        $this->hooked->loggable = ['c-1', 'c-2', 'c-3', 'c-9'];
        $this->hookedTypes      = new HookedActivityTypeProvider();
        foreach ($this->containers() as $container) {
            $provider = $container->get(CustomerTestSubjectProvider::class);
            self::assertInstanceOf(CustomerTestSubjectProvider::class, $provider);
            $provider->reset();
            $provider->loggable = ['c-1', 'c-2', 'c-3', 'c-9'];
            $types              = $container->get(FixedActivityTypeProvider::class);
            self::assertInstanceOf(FixedActivityTypeProvider::class, $types);
            $types->reset();
        }
    }

    protected function tearDown(): void
    {
        if ($this->otherKernel instanceof KernelInterface) {
            $em = $this->otherKernel->getContainer()->get('test.service_container');
            if ($em instanceof ContainerInterface) {
                $manager = $em->get(TimeTrackEntityManager::class);
                if ($manager instanceof EntityManagerInterface) {
                    $manager->getConnection()->close();
                }
            }
            $this->otherKernel->shutdown();
            $this->otherKernel = null;
        }
        parent::tearDown();
    }

    /**
     * Double stop from two devices with different subjects: the other device's stop commits after this one loaded the
     * timer; only the call that deletes the timer row records the entry, with ITS subject.
     */
    public function testADoubleStopWithDifferentSubjectsRecordsOneEntryWithTheWinnersSubject(): void
    {
        $this->timers()->start($this->user, 'task-1', ClientType::Desktop);
        $this->hooked->onCanLog = function (): void {
            $entry = $this->otherTimers()->stop($this->otherUser(), null, null, TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-2')), true);
            self::assertInstanceOf(TimeEntry::class, $entry);
        };

        $loser = $this->hookedTimers()->stop($this->user, null, null, TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-1')), true);

        self::assertNull($loser, 'the loser records nothing (404 no_active_timer)');
        self::assertSame([['customer', 'c-2', 'task-1']], $this->entryRows(), 'one entry, the winner\'s subject next to the task');
        self::assertSame(0, $this->timerCount());
        self::assertFalse($this->connection()->isTransactionActive());
    }

    /**
     * Refused stop vs stop with a subject, the refused one loads first: the stop with a subject commits before the
     * refused one locks; the refused stop then finds no timer (null, no exception) and nothing is lost.
     */
    public function testAStopWithASubjectCommittingFirstLeavesNothingToRefuse(): void
    {
        $this->timers()->start($this->user, TimeAllocation::none(), ClientType::Desktop);
        $this->policy()->onAsk = function (): void {
            self::assertInstanceOf(TimeEntry::class, $this->otherTimers()->stop($this->otherUser(), null, null, TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-1')), true));
        };

        self::assertNull($this->timers()->stop($this->user, null, null, null, true));
        self::assertSame([['customer', 'c-1', null]], $this->entryRows());
        self::assertSame(0, $this->timerCount());
    }

    /**
     * Refused stop vs stop with a subject, the stop with a subject loads first: the refused stop runs to its end in
     * between (it throws inside its unit: nothing written, the timer stays), then the stop with a subject records.
     */
    public function testARefusedStopInBetweenWritesNothingAndLeavesTheTimerToTheOtherStop(): void
    {
        $this->timers()->start($this->user, TimeAllocation::none(), ClientType::Desktop);
        $refused                = null;
        $this->hooked->onCanLog = function () use (&$refused): void {
            try {
                $this->otherTimers()->stop($this->otherUser(), null, null, null, true);
            } catch (TimeAllocationRequiredException $exception) {
                $refused = $exception;
            }
            self::assertSame(1, $this->timerCount(), 'the refused stop rolled back: the timer still runs');
            self::assertSame([], $this->entryRows());
        };

        $entry = $this->hookedTimers()->stop($this->user, null, null, TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-1')), true);

        self::assertInstanceOf(TimeAllocationRequiredException::class, $refused);
        self::assertSame(TimeAllocationException::ALLOCATION_REQUIRED, $refused->errorCode);
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame([['customer', 'c-1', null]], $this->entryRows());
        self::assertSame(0, $this->timerCount());
    }

    /**
     * Stop racing `timer/allocate`, allocate first: the allocation commits after the stop loaded the timer (no
     * subject then); the stop's re-read timer carries the subject, so the interactive stop is NOT refused and the
     * entry records it.
     */
    public function testAnAllocationCommittedBeforeTheStopLocksIsRecordedByTheStop(): void
    {
        $this->timers()->start($this->user, TimeAllocation::none(), ClientType::Desktop);
        $this->policy()->onAsk = function (): void {
            self::assertNotNull($this->otherTimers()->allocateRunning($this->otherUser(), TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-3'))));
        };

        $entry = $this->timers()->stop($this->user, null, null, null, true);

        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame('c-3', $entry->subject()?->id);
        self::assertSame([['customer', 'c-3', null]], $this->entryRows());
        self::assertSame(0, $this->timerCount());
    }

    /**
     * Stop racing `timer/allocate`, stop first: the stop commits after the allocation loaded the timer; the allocation
     * finds no timer (null → 404 no_active_timer) and writes nothing: the entry keeps the stop's subject.
     */
    public function testAnAllocationAfterAStopCommittedMeanwhileFindsNoTimer(): void
    {
        $this->timers()->start($this->user, 'task-1', ClientType::Desktop);
        $this->hooked->onCanLog = function (): void {
            self::assertInstanceOf(TimeEntry::class, $this->otherTimers()->stop($this->otherUser(), null, null, TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-2')), true));
        };

        self::assertNull($this->hookedTimers()->allocateRunning($this->user, TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-1'))));

        self::assertSame([['customer', 'c-2', 'task-1']], $this->entryRows());
        self::assertSame(0, $this->timerCount());
    }

    /**
     * Correct racing correct on the allocation: both read the same version; the other one commits its subject first.
     * The version covers the subject, so this one is `conflict`, never an overwrite.
     */
    public function testASecondCorrectionOfTheAllocationIsAConflict(): void
    {
        $id                     = $this->entry(new TimeSubjectRef('customer', 'c-1'));
        $version                = $this->version($id);
        $this->hooked->onCanLog = function () use ($id, $version): void {
            $result = $this->otherOperations()->correct((string) $this->user->getId(), $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-3'))));
            self::assertSame(['subject'], $result->changedFields);
        };

        $this->assertConflict(fn (): mixed => $this->hookedOperations()->correct((string) $this->user->getId(), $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-2')))));

        self::assertSame([['customer', 'c-3', 'task-1']], $this->entryRows(), 'the first correction stays');
        self::assertNotSame($version, $this->version($id));
    }

    /**
     * Correct racing an activity type correction: same rule for the activity type key (the other request sets it
     * first; this one, built on the old version, is `conflict`).
     */
    public function testASecondCorrectionOfTheActivityTypeIsAConflict(): void
    {
        $id                        = $this->entry(null);
        $version                   = $this->version($id);
        $this->hookedTypes->onList = function () use ($id, $version): void {
            $this->otherOperations()->correct((string) $this->user->getId(), $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withActivityType('support')));
        };

        $this->assertConflict(fn (): mixed => $this->hookedOperations()->correct((string) $this->user->getId(), $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withActivityType('dev'))));

        self::assertSame('support', $this->connection()->fetchOne('SELECT activity_type_key FROM time_track_entries WHERE id = ?', [$id]));
    }

    /**
     * Correct racing the documented back-fill (`UPDATE … WHERE subject_type IS NULL`, a host migration on its own
     * connection): the back-fill changes the version, so the correction is `conflict`; re-running the back-fill after
     * it touches nothing.
     */
    public function testACorrectionRacingTheBackFillIsAConflict(): void
    {
        $id                     = $this->entry(null);
        $version                = $this->version($id);
        $backFill               = (fn (): int => (int) $this->otherConnection()->executeStatement('UPDATE time_track_entries SET subject_type = ?, subject_id = ? WHERE id = ? AND subject_type IS NULL AND subject_id IS NULL', ['customer', 'c-9', $id]));
        $this->hooked->onCanLog = static function () use ($backFill): void {
            self::assertSame(1, $backFill());
        };

        $this->assertConflict(fn (): mixed => $this->hookedOperations()->correct((string) $this->user->getId(), $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-2')))));

        self::assertSame([['customer', 'c-9', 'task-1']], $this->entryRows(), 'the back-fill stays');
        self::assertSame(0, $backFill(), 'the back-fill only touches unallocated rows: safe to run again');
        // The correction retried with the version read now goes through.
        $this->clearEntityManagers();
        $result = $this->operations()->correct((string) $this->user->getId(), $id, $this->version($id), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject(new TimeSubjectRef('customer', 'c-2'))));
        self::assertSame('c-2', $result->after->entry->subject?->id);
    }

    /**
     * Asserts the call fails with `conflict`, with nothing left open on the connection.
     *
     * @param Closure(): mixed $call
     */
    private function assertConflict(Closure $call): void
    {
        try {
            $call();
            self::fail('expected a conflict');
        } catch (TimeEntryCorrectionException $exception) {
            self::assertSame(TimeEntryCorrectionException::CONFLICT, $exception->errorCode);
        }
        self::assertFalse($this->connection()->isTransactionActive());
    }

    /**
     * A task entry two days ago, with the subject given, committed; its id.
     */
    private function entry(?TimeSubjectRef $subject): string
    {
        $start = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $entry = (new TimeEntry($this->user, 'task-1', 'Task', null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web))->allocate($subject, null);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry->getId();
    }

    private function version(string $id): string
    {
        $query = self::getContainer()->get(TimeEntryQueryInterface::class);
        self::assertInstanceOf(TimeEntryQueryInterface::class, $query);

        return ($query->find($id) ?? self::fail('no entry ' . $id))->version();
    }

    /**
     * Every stored entry as [subject_type, subject_id, task_id], read through SQL.
     *
     * @return list<array{0: mixed, 1: mixed, 2: mixed}>
     */
    private function entryRows(): array
    {
        $rows = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT subject_type, subject_id, task_id FROM time_track_entries ORDER BY started_at') as $row) {
            $rows[] = [$row['subject_type'], $row['subject_id'], $row['task_id']];
        }

        return $rows;
    }

    private function timerCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    private function operations(): TimeEntryOperationsInterface
    {
        $operations = self::getContainer()->get(TimeEntryOperationsInterface::class);
        self::assertInstanceOf(TimeEntryOperations::class, $operations);

        return $operations;
    }

    /**
     * This kernel's timer service with the hooked providers (customer + activity types) as its allocation guard.
     */
    private function hookedTimers(): TimerService
    {
        return ServiceCopy::with($this->timers(), ['allocations' => AllocationFixtures::guard([$this->hooked], $this->hookedTypes, requireAllocation: true, allowWithoutTask: true)]);
    }

    /**
     * This kernel's entry operations over {@see self::hookedTimers()}.
     */
    private function hookedOperations(): TimeEntryOperationsInterface
    {
        $operations = $this->operations();
        $manual     = self::getContainer()->get(ManualEntryService::class);
        self::assertInstanceOf(ManualEntryService::class, $manual);

        return ServiceCopy::with($operations, ['manualEntries' => ServiceCopy::with($manual, ['timerService' => $this->hookedTimers()])]);
    }

    private function policy(): StubServiceAccountPolicy
    {
        $policy = self::getContainer()->get(StubServiceAccountPolicy::class);
        self::assertInstanceOf(StubServiceAccountPolicy::class, $policy);

        return $policy;
    }

    /**
     * The other request's timer service (second kernel on a second connection, or this kernel on in-memory SQLite).
     */
    private function otherTimers(): TimerService
    {
        $timers = $this->otherContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    private function otherOperations(): TimeEntryOperationsInterface
    {
        $operations = $this->otherContainer()->get(TimeEntryOperationsInterface::class);
        self::assertInstanceOf(TimeEntryOperationsInterface::class, $operations);

        return $operations;
    }

    /**
     * The test user as the other request loads it (its own entity manager on a second connection).
     */
    private function otherUser(): AppUser
    {
        if (!$this->hasSecondConnection()) {
            return $this->user;
        }
        $em = $this->otherContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = $em->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    /**
     * A connection of its own for the back-fill (the main one on in-memory SQLite).
     */
    private function otherConnection(): Connection
    {
        if (!$this->hasSecondConnection()) {
            return $this->connection();
        }
        $em = $this->otherContainer()->get(TimeTrackEntityManager::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();
        self::assertNotSame($this->connection(), $connection);

        return $connection;
    }

    private function hasSecondConnection(): bool
    {
        return FunctionalKernel::databaseUrl() !== 'sqlite:///:memory:';
    }

    /**
     * The other request's container: a second kernel booted on the same database (a second real connection), or this
     * kernel's on in-memory SQLite.
     */
    private function otherContainer(): ContainerInterface
    {
        if (!$this->hasSecondConnection()) {
            return self::getContainer();
        }
        if (!$this->otherKernel instanceof KernelInterface) {
            $this->otherKernel = new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
            $this->otherKernel->boot();
            $container = $this->otherKernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $provider = $container->get(CustomerTestSubjectProvider::class);
            self::assertInstanceOf(CustomerTestSubjectProvider::class, $provider);
            $provider->loggable = ['c-1', 'c-2', 'c-3', 'c-9'];
        }
        $container = $this->otherKernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * This kernel's container and, with a second connection, the other kernel's.
     *
     * @return list<ContainerInterface>
     */
    private function containers(): array
    {
        return $this->hasSecondConnection() ? [self::getContainer(), $this->otherContainer()] : [self::getContainer()];
    }
}
