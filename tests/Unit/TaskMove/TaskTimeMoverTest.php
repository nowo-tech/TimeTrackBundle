<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\TaskMove;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TaskTimeMoveRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TaskTimerMoveRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeKeptReason;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeMover;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeMoveResult;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Since 1.17: the task time mover's rules that need no database (the Doctrine paths are in the functional TaskTimeMoveTest).
 */
final class TaskTimeMoverTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<mixed>, 1: string|null, 2: string|null}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'empty task id' => [[''], 'board', 'scope'];
        yield 'task id not a string' => [[7], 'board', 'scope'];
        yield 'task id too long' => [[str_repeat('a', 37)], 'board', 'scope'];
        yield 'empty board' => [['task'], '', 'scope'];
        yield 'board too long' => [['task'], str_repeat('b', 37), 'scope'];
        yield 'scope too long' => [['task'], 'board', str_repeat('s', 65)];
    }

    /**
     * @param array<mixed> $taskIds
     */
    #[DataProvider('invalidArguments')]
    public function testAnInvalidArgumentWritesNothing(array $taskIds, ?string $boardId, ?string $scopeKey): void
    {
        /** @var MockObject&TaskTimeMoveRepositoryInterface&TimeEntryRepositoryInterface $entries */
        $entries = $this->createMockForIntersectionOfInterfaces([TimeEntryRepositoryInterface::class, TaskTimeMoveRepositoryInterface::class]);
        $entries->expects(self::never())->method('inTaskMoveTransaction');

        $this->expectException(InvalidArgumentException::class);
        /* @phpstan-ignore argument.type */
        (new TaskTimeMover($entries, $this->timers()))->moveTasks($taskIds, $boardId, $scopeKey);
    }

    public function testStoresWithoutTheCapabilityAreRefused(): void
    {
        $this->expectException(LogicException::class);
        (new TaskTimeMover(new InMemoryTimeEntryRepository(), new InMemoryActiveTimerRepository()))->moveTasks(['task'], 'board', 'scope');
    }

    public function testATimerStoreWithoutTheCapabilityIsRefused(): void
    {
        /** @var Stub&TaskTimeMoveRepositoryInterface&TimeEntryRepositoryInterface $entries */
        $entries = $this->createStubForIntersectionOfInterfaces([TimeEntryRepositoryInterface::class, TaskTimeMoveRepositoryInterface::class]);

        $this->expectException(LogicException::class);
        (new TaskTimeMover($entries, new InMemoryActiveTimerRepository()))->moveTasks(['task'], 'board', 'scope');
    }

    public function testNoTaskIsNothingToDo(): void
    {
        /** @var MockObject&TaskTimeMoveRepositoryInterface&TimeEntryRepositoryInterface $entries */
        $entries = $this->createMockForIntersectionOfInterfaces([TimeEntryRepositoryInterface::class, TaskTimeMoveRepositoryInterface::class]);
        $entries->expects(self::never())->method('inTaskMoveTransaction');

        $result = (new TaskTimeMover($entries, $this->timers()))->moveTasks([], 'board', null);

        self::assertFalse($result->hasChanges());
        self::assertTrue($result->isComplete());
    }

    public function testWithoutTimesheetsOnlyAnonymisedEntriesStay(): void
    {
        $at   = new DateTimeImmutable('2026-01-01 10:00:00');
        $rows = [
            ['id' => 'a', 'taskId' => 'task', 'boardId' => 'old', 'scopeKey' => 's', 'seconds' => '60', 'startedAt' => $at, 'personRef' => null, 'userId' => null, 'anonymisedAt' => null],
            ['id' => 'b', 'taskId' => 'task', 'boardId' => 'old', 'scopeKey' => 's', 'seconds' => 30, 'startedAt' => $at, 'personRef' => null, 'userId' => null, 'anonymisedAt' => $at],
            ['id' => 'c', 'taskId' => 'task', 'boardId' => 'new', 'scopeKey' => 't', 'seconds' => 10, 'startedAt' => $at, 'personRef' => null, 'userId' => 7, 'anonymisedAt' => null],
        ];
        /** @var MockObject&TaskTimeMoveRepositoryInterface&TimeEntryRepositoryInterface $entries */
        $entries = $this->createMockForIntersectionOfInterfaces([TimeEntryRepositoryInterface::class, TaskTimeMoveRepositoryInterface::class]);
        $entries->method('inTaskMoveTransaction')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $entries->method('findForTaskMove')->willReturnOnConsecutiveCalls($rows, []);
        $entries->expects(self::once())->method('applyTaskMove')->with(['a'], 'new', 't')->willReturn(1);

        $result = (new TaskTimeMover($entries, $this->timers(), new PeriodLockGuard(new InMemoryTimesheetRepository(), false)))->moveTasks(['task'], 'new', 't');

        self::assertSame([1, 1, 60, 30], [$result->moved, $result->alreadyAtTarget, $result->secondsMoved, $result->secondsKept]);
        self::assertSame(['b' => TaskTimeKeptReason::Anonymised], $result->kept);
        self::assertSame(['b'], $result->keptIds(TaskTimeKeptReason::Anonymised));
        self::assertSame([], $result->keptIds(TaskTimeKeptReason::SourcePeriodLocked));
    }

    public function testResultsAddUp(): void
    {
        $sum = (new TaskTimeMoveResult(1, 2, 3, ['x' => TaskTimeKeptReason::OwnerUnknown], 4, 5))
            ->merge(new TaskTimeMoveResult(10, 20, 30, ['y' => TaskTimeKeptReason::TargetPeriodLocked], 40, 50));

        self::assertSame([11, 22, 33, 44, 55, 2], [$sum->moved, $sum->alreadyAtTarget, $sum->secondsMoved, $sum->secondsKept, $sum->timersMoved, $sum->keptCount()]);
        self::assertSame(['x', 'y'], $sum->keptEntryIds());
        self::assertTrue((new TaskTimeMoveResult(timersMoved: 1))->hasChanges());
        self::assertTrue((new TaskTimeMoveResult(kept: ['1' => TaskTimeKeptReason::Anonymised]))->hasChanges());
        self::assertSame(['1'], (new TaskTimeMoveResult(kept: ['1' => TaskTimeKeptReason::Anonymised]))->keptEntryIds(), 'a numeric id is still a string');
    }

    private function timers(): ActiveTimerRepositoryInterface&TaskTimerMoveRepositoryInterface
    {
        /** @var ActiveTimerRepositoryInterface&Stub&TaskTimerMoveRepositoryInterface $timers */
        $timers = $this->createStubForIntersectionOfInterfaces([ActiveTimerRepositoryInterface::class, TaskTimerMoveRepositoryInterface::class]);
        $timers->method('moveTimersToBoard')->willReturn(0);

        return $timers;
    }
}
