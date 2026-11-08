<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Event\TaskTimeMovedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TaskTimeMoveRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeKeptReason;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeMover;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use PHPUnit\Framework\MockObject\Stub;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function array_values;
use function ksort;
use function sort;
use function sprintf;

/**
 * Since 1.17 (Beacon spec 217 FR-008): the time of a task moved to another board follows it, into another tenant too, and
 * never changes anything but the board snapshot and the scope. Locked, approved and submitted periods keep their entries.
 */
final class TaskTimeMoveTest extends FunctionalTestCase
{
    private const COLUMNS = 'id, created_at, external_source, external_id, billable, description, person_name_snapshot, person_ref, anonymised_at, user_id, task_id, task_title_snapshot, started_at, ended_at, duration_seconds, source, metadata';

    /** @var list<TaskTimeMovedEvent> */
    private array $events = [];

    protected static function timeTrackConfig(): array
    {
        return ['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => StubTimesheetApproverProvider::class]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = [];
        $dispatcher   = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::TASK_TIME_MOVED, function (TaskTimeMovedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testTheTimeFollowsTheTaskIntoAnotherTenantAndTotalsStayTheSame(): void
    {
        $other = $this->createUser('other@example.com');
        $this->entry('task-1', '2026-02-02 09:00:00', 3600);
        $this->entry('task-1', '2026-02-03 09:00:00', 1800, user: $other);
        $this->entry('task-2', '2026-02-04 09:00:00', 900, source: TimeEntrySource::Import);
        $this->entry('task-9', '2026-02-05 09:00:00', 600);
        $before = $this->totalsByUser();

        $result = $this->mover()->moveTasks(['task-1', 'task-2', 'task-1'], 'board-b', 'tenant-b', 'actor-7');

        self::assertSame([3, 0, 6300, 0, 0], [$result->moved, $result->alreadyAtTarget, $result->secondsMoved, $result->keptCount(), $result->timersMoved]);
        self::assertTrue($result->isComplete());
        // SC-001: count and total per user are identical before and after.
        self::assertSame($before, $this->totalsByUser());
        // Reports and billing by scope: the hours left tenant-a and count in tenant-b.
        self::assertSame(6300, $this->secondsInScope('tenant-b'));
        self::assertSame(600, $this->secondsInScope('tenant-a'), 'another task stays');
        self::assertSame(['board-a'], $this->column('board_id_snapshot', "task_id = 'task-9'"));
        self::assertSame(['board-b', 'board-b', 'board-b'], $this->column('board_id_snapshot', "task_id IN ('task-1', 'task-2')"));

        self::assertCount(1, $this->events);
        self::assertSame(['task-1', 'task-2'], $this->events[0]->getTaskIds());
        self::assertSame(['board-b', 'tenant-b', 'actor-7'], [$this->events[0]->getTargetBoardId(), $this->events[0]->getTargetScopeKey(), $this->events[0]->getActorId()]);
        self::assertSame($result, $this->events[0]->getResult());
    }

    public function testOnlyTheBoardSnapshotAndTheScopeChange(): void
    {
        $this->entry('task-1', '2026-02-02 09:00:00', 3600, source: TimeEntrySource::Import, metadata: ['note' => 'kept']);
        $this->entry('task-1', '2026-02-02 11:00:00', 1200, personRef: 'p-1');
        $this->entry('task-1', '2026-02-02 13:00:00', 600, source: TimeEntrySource::Manual);
        $conn   = $this->entityManager()->getConnection();
        $before = $conn->fetchAllAssociative(sprintf('SELECT %s FROM time_track_entries ORDER BY id', self::COLUMNS));

        self::assertSame(3, $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b')->moved);

        self::assertSame($before, $conn->fetchAllAssociative(sprintf('SELECT %s FROM time_track_entries ORDER BY id', self::COLUMNS)));
        self::assertSame(3, (int) $conn->fetchOne("SELECT COUNT(*) FROM time_track_entries WHERE board_id_snapshot = 'board-b' AND scope_key = 'tenant-b'"));
        $this->entityManager()->clear();
        foreach ($this->entityManager()->getRepository(TimeEntry::class)->findAll() as $entry) {
            self::assertSame($entry->getSource() === TimeEntrySource::Import, $entry->isReadOnly());
        }
    }

    public function testARetryFinishesWhatAFailedCallLeftAndThenChangesNothing(): void
    {
        $first  = $this->entry('task-1', '2026-02-02 09:00:00', 3600);
        $second = $this->entry('task-1', '2026-02-03 09:00:00', 1800);
        $store  = $this->store();
        $calls  = 0;
        /** @var Stub&TaskTimeMoveRepositoryInterface&TimeEntryRepositoryInterface $failing */
        $failing = $this->createStubForIntersectionOfInterfaces([TimeEntryRepositoryInterface::class, TaskTimeMoveRepositoryInterface::class]);
        $failing->method('inTaskMoveTransaction')->willReturnCallback(static fn (callable $operation): mixed => $store->inTaskMoveTransaction($operation));
        $failing->method('findForTaskMove')->willReturnCallback(static fn (array $taskIds, string $after, int $limit): array => $store->findForTaskMove(array_map(strval(...), array_values($taskIds)), $after, $limit));
        $failing->method('applyTaskMove')->willReturnCallback(static function (array $ids, ?string $board, ?string $scope) use ($store, &$calls): int {
            $store->applyTaskMove(array_map(strval(...), array_values($ids)), $board, $scope);

            throw new RuntimeException('Listener failed after the update.' . ++$calls);
        });
        $timers = self::getContainer()->get(ActiveTimerRepositoryInterface::class);
        self::assertInstanceOf(ActiveTimerRepositoryInterface::class, $timers);

        try {
            (new TaskTimeMover($failing, $timers, $this->guard()))->moveTasks(['task-1'], 'board-b', 'tenant-b');
            self::fail('The failure is not hidden.');
        } catch (RuntimeException $exception) {
            self::assertSame('Listener failed after the update.1', $exception->getMessage());
        }
        // The batch rolled back: the entries are still there, with the old snapshot.
        self::assertSame(['tenant-a', 'tenant-a'], $this->column('scope_key', "task_id = 'task-1'"));
        // Part of a former call got through (another batch, or a host that moved one row itself).
        $this->entityManager()->getConnection()->executeStatement("UPDATE time_track_entries SET board_id_snapshot = 'board-b', scope_key = 'tenant-b' WHERE id = ?", [$first]);

        $retry = $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b');
        self::assertSame([1, 1, 1800], [$retry->moved, $retry->alreadyAtTarget, $retry->secondsMoved]);
        self::assertSame(['tenant-b', 'tenant-b'], $this->column('scope_key', "task_id = 'task-1'"));

        $again = $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b');
        self::assertSame([0, 2, 0], [$again->moved, $again->alreadyAtTarget, $again->secondsMoved]);
        self::assertFalse($again->hasChanges());
        self::assertCount(1, $this->events, 'a call that changed nothing fires nothing');
        self::assertSame(2, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
        self::assertNotSame($first, $second);
    }

    public function testLockedApprovedAndSubmittedPeriodsKeepTheirEntries(): void
    {
        $lockedSource    = $this->entry('task-1', '2026-01-01 10:00:00', 3600);
        $approvedSource  = $this->entry('task-1', '2026-01-28 10:00:00', 1800);
        $submittedTarget = $this->entry('task-1', '2026-01-07 10:00:00', 900);
        $open            = $this->entry('task-1', '2026-01-14 10:00:00', 600);
        $locked          = $this->timesheet($this->user, 'tenant-a', '2026-W01');
        $locked->applyLock(new DateTimeImmutable('2026-01-10 08:00:00'), TimesheetLockReason::Manual);
        $this->timesheet($this->user, 'tenant-a', '2026-W05')->applyApprove(new DateTimeImmutable('2026-02-02 08:00:00'), 'approver', 'hash', null);
        $this->timesheet($this->user, 'tenant-b', '2026-W02')->applySubmit(new DateTimeImmutable('2026-01-12 08:00:00'), (string) $this->user->getId(), 'hash', null);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $result = $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b');

        self::assertSame([1, 600, 6300], [$result->moved, $result->secondsMoved, $result->secondsKept]);
        self::assertSame($this->sorted([$lockedSource, $approvedSource]), $this->sorted($result->keptIds(TaskTimeKeptReason::SourcePeriodLocked)));
        self::assertSame([$submittedTarget], $result->keptIds(TaskTimeKeptReason::TargetPeriodLocked));
        self::assertSame($this->sorted([$lockedSource, $approvedSource, $submittedTarget]), $this->sorted($result->keptEntryIds()));
        self::assertFalse($result->isComplete());
        // The kept rows are untouched: board and scope as before.
        foreach ([$lockedSource, $approvedSource, $submittedTarget] as $id) {
            self::assertSame(['board-a', 'tenant-a'], $this->boardAndScope($id));
        }
        self::assertSame(['board-b', 'tenant-b'], $this->boardAndScope($open));
        self::assertSame(1, $this->events[0]->getResult()->moved);

        // An audited reopen of the source period, then the same call again: that entry moves.
        $timesheet = $this->entityManager()->find(Timesheet::class, $locked->getId());
        self::assertInstanceOf(Timesheet::class, $timesheet);
        $timesheet->applyReopen(new DateTimeImmutable('2026-02-03 08:00:00'), 'approver', 'Move the task time');
        $this->entityManager()->flush();
        $retry = $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b');
        self::assertSame([1, 1, 2], [$retry->moved, $retry->alreadyAtTarget, $retry->keptCount()]);
        self::assertSame(['board-b', 'tenant-b'], $this->boardAndScope($lockedSource));
    }

    public function testAMoveWithinOneScopeStillLeavesALockedPeriodAlone(): void
    {
        $id = $this->entry('task-1', '2026-01-01 10:00:00', 3600);
        $this->timesheet($this->user, 'tenant-a', '2026-W01')->applyLock(new DateTimeImmutable('2026-01-10 08:00:00'), TimesheetLockReason::Manual);
        $this->entityManager()->flush();

        $result = $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-a');

        self::assertSame([TaskTimeKeptReason::SourcePeriodLocked], array_values($result->kept));
        self::assertSame(['board-a', 'tenant-a'], $this->boardAndScope($id));
    }

    public function testAnonymisedEntriesAndEntriesOfNobodyStay(): void
    {
        $anonymised = $this->entry('task-1', '2026-02-02 09:00:00', 3600);
        $nobody     = $this->entry('task-1', '2026-02-02 11:00:00', 600);
        $conn       = $this->entityManager()->getConnection();
        $conn->executeStatement("UPDATE time_track_entries SET user_id = NULL, anonymised_at = '2026-02-03 08:00:00' WHERE id = ?", [$anonymised]);
        $conn->executeStatement('UPDATE time_track_entries SET user_id = NULL WHERE id = ?', [$nobody]);

        $result = $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b');

        self::assertSame($this->sortedByKey([$anonymised => TaskTimeKeptReason::Anonymised, $nobody => TaskTimeKeptReason::OwnerUnknown]), $this->sortedByKey($result->kept));
        self::assertSame(0, $result->moved);
    }

    public function testAFormerUsersEntryIsJudgedByTheirTimesheets(): void
    {
        $id = $this->entry('task-1', '2026-01-01 10:00:00', 3600);
        $this->timesheet($this->user, 'tenant-a', '2026-W01')->applyLock(new DateTimeImmutable('2026-01-10 08:00:00'), TimesheetLockReason::Manual);
        $this->entityManager()->flush();
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_entries SET user_id = NULL, person_ref = ? WHERE id = ?', ['user:' . $this->user->getId(), $id]);

        self::assertSame([$id], $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b')->keptIds(TaskTimeKeptReason::SourcePeriodLocked));
    }

    public function testRunningTimersFollowTheTask(): void
    {
        $other = $this->createUser('other@example.com');
        $third = $this->createUser('third@example.com');
        $em    = $this->entityManager();
        $timer = new ActiveTimer($this->user, 'task-1', 'Task', 'board-a', ClientType::Web);
        $em->persist($timer);
        $em->persist(new ActiveTimer($other, 'task-9', 'Task', 'board-a', ClientType::Web));
        $em->persist(new ActiveTimer($third, 'task-2', 'Task', null, ClientType::Web));
        $em->flush();

        $result = $this->mover()->moveTasks(['task-1', 'task-2'], 'board-b', 'tenant-b');

        self::assertSame(2, $result->timersMoved);
        self::assertFalse($em->contains($timer), 'a loaded timer is detached');
        $em->flush();
        $conn = $em->getConnection();
        self::assertSame(['board-b'], array_map(strval(...), $conn->fetchFirstColumn("SELECT board_id_snapshot FROM time_track_active_timers WHERE task_id = 'task-1'")));
        self::assertSame(['board-a'], array_map(strval(...), $conn->fetchFirstColumn("SELECT board_id_snapshot FROM time_track_active_timers WHERE task_id = 'task-9'")));
        self::assertSame(0, $this->mover()->moveTasks(['task-1', 'task-2'], 'board-b', 'tenant-b')->timersMoved, 'a retry changes nothing');

        self::assertSame(1, $this->mover()->moveTasks(['task-1'], null, null)->timersMoved);
        self::assertSame(0, $this->mover()->moveTasks(['task-1'], null, null)->timersMoved);
        self::assertNull($conn->fetchOne("SELECT board_id_snapshot FROM time_track_active_timers WHERE task_id = 'task-1'"));
    }

    public function testALoadedEntryIsDetachedAndAFlushDoesNotWriteItBack(): void
    {
        $id    = $this->entry('task-1', '2026-02-02 09:00:00', 3600);
        $em    = $this->entityManager();
        $entry = $em->find(TimeEntry::class, $id);
        self::assertInstanceOf(TimeEntry::class, $entry);

        $this->mover()->moveTasks(['task-1'], 'board-b', 'tenant-b');
        $em->flush();

        self::assertFalse($em->contains($entry));
        self::assertSame(['board-b', 'tenant-b'], $this->boardAndScope($id));
    }

    public function testManyTasksAndEntriesMoveInBatches(): void
    {
        $conn  = $this->entityManager()->getConnection();
        $tasks = [];
        $conn->beginTransaction();
        for ($task = 0; $task < TaskTimeMover::TASK_CHUNK + 1; ++$task) {
            $tasks[] = 't-' . $task;
            for ($n = 0; $n < 3; ++$n) {
                $conn->insert('time_track_entries', ['id' => sprintf('e-%04d-%d', $task, $n), 'created_at' => '2026-02-02 10:00:00', 'task_id' => 't-' . $task, 'task_title_snapshot' => 'T', 'board_id_snapshot' => 'board-a', 'started_at' => '2026-02-02 10:00:00', 'ended_at' => '2026-02-02 10:01:00', 'duration_seconds' => 60, 'source' => 'web', 'scope_key' => 'tenant-a', 'user_id' => $this->user->getId()]);
            }
        }
        $conn->commit();

        $result = $this->mover()->moveTasks($tasks, 'board-b', 'tenant-b');

        self::assertSame([603, 603 * 60], [$result->moved, $result->secondsMoved]);
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM time_track_entries WHERE scope_key <> 'tenant-b'"));
    }

    public function testTheStoresAnswerNothingForNoTask(): void
    {
        self::assertSame([], $this->store()->findForTaskMove([], '', 10));
        $timers = self::getContainer()->get(ActiveTimerRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmActiveTimerRepository::class, $timers);
        self::assertSame(0, $timers->moveTimersToBoard([], 'board-b'));
        self::assertInstanceOf(TaskTimeMover::class, self::getContainer()->get(TaskTimeMover::class));
    }

    private function mover(): TaskTimeMover
    {
        $mover = self::getContainer()->get(TaskTimeMover::class);
        self::assertInstanceOf(TaskTimeMover::class, $mover);

        return $mover;
    }

    private function store(): DoctrineOrmTimeEntryRepository
    {
        $store = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $store);

        return $store;
    }

    private function guard(): PeriodLockGuard
    {
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);

        return $guard;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function entry(string $taskId, string $start, int $seconds, ?AppUser $user = null, ?string $personRef = null, TimeEntrySource $source = TimeEntrySource::Web, ?array $metadata = null): string
    {
        $user ??= $personRef === null ? $this->user : null;
        $from  = new DateTimeImmutable($start);
        $entry = new TimeEntry($user, $taskId, 'Task', 'board-a', $from, $from->modify(sprintf('+%d seconds', $seconds)), $seconds, $source, $metadata);
        $entry->assignScopeKey('tenant-a');
        $em = $this->entityManager();
        $em->persist($entry);
        $em->flush();
        if ($personRef !== null) {
            $em->getConnection()->executeStatement('UPDATE time_track_entries SET person_ref = ? WHERE id = ?', [$personRef, $entry->getId()]);
        }

        return $entry->getId();
    }

    private function timesheet(AppUser $user, string $scopeKey, string $week): Timesheet
    {
        $timesheet = new Timesheet($user, (string) $user->getId(), $scopeKey, Period::fromKey(TimesheetPeriodKind::Week, $week), 'UTC', new DateTimeImmutable('2026-01-01 08:00:00'));
        $this->entityManager()->persist($timesheet);
        $this->entityManager()->flush();
        self::assertSame(TimesheetStatus::Open, $timesheet->getStatus());

        return $timesheet;
    }

    /**
     * @return array<string, array{0: int, 1: int}> entries and seconds per user
     */
    private function totalsByUser(): array
    {
        $totals = [];
        foreach ($this->entityManager()->getConnection()->fetchAllAssociative('SELECT user_id, COUNT(*) AS n, SUM(duration_seconds) AS s FROM time_track_entries GROUP BY user_id ORDER BY user_id') as $row) {
            $totals['u' . $row['user_id']] = [(int) $row['n'], (int) $row['s']];
        }

        return $totals;
    }

    private function secondsInScope(string $scopeKey): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne('SELECT COALESCE(SUM(duration_seconds), 0) FROM time_track_entries WHERE scope_key = ?', [$scopeKey]);
    }

    /**
     * @return list<string>
     */
    private function column(string $column, string $where): array
    {
        return array_map(strval(...), $this->entityManager()->getConnection()->fetchFirstColumn(sprintf('SELECT %s FROM time_track_entries WHERE %s ORDER BY %1$s', $column, $where)));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function boardAndScope(string $id): array
    {
        $row = $this->entityManager()->getConnection()->fetchAssociative('SELECT board_id_snapshot, scope_key FROM time_track_entries WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return [(string) $row['board_id_snapshot'], (string) $row['scope_key']];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @param array<array-key, TaskTimeKeptReason> $kept
     *
     * @return array<array-key, TaskTimeKeptReason>
     */
    private function sortedByKey(array $kept): array
    {
        ksort($kept);

        return $kept;
    }
}
