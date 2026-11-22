<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\ScopeBackfillRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;

use function array_map;
use function sort;

/**
 * Since 1.36.0 (coordinator decision 2026-11-22): with `entries.allow_without_task` off (the default), entries without a
 * task stored while it was on keep the tenant rule: a manager of another scope neither lists, counts nor edits them,
 * and one without a scope stays its owner's only. Entries with a task are listed as in 1.35.
 */
final class EntriesWithoutTaskOptionOffTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets' => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
        ];
    }

    public function testAStoredEntryWithoutATaskNeverCrossesScopesWithTheOptionOff(): void
    {
        $managerB = $this->createUser('manager-b@example.com', ['ROLE_ADMIN']);
        $managerA = $this->createUser('manager-a@example.com', ['ROLE_ADMIN']);
        $scopes   = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a', 'org-b'], (string) $managerB->getId() => ['org-b'], (string) $managerA->getId() => ['org-a']];

        $inA      = $this->save((new TimeEntry($this->user, null, '', null, new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), 3600, TimeEntrySource::Manual))->describe('ACME')->assignScopeKey('org-a'));
        $inB      = $this->save((new TimeEntry($this->user, null, '', null, new DateTimeImmutable('-9 hours'), new DateTimeImmutable('-8 hours'), 3600, TimeEntrySource::Manual))->describe('Other customer')->assignScopeKey('org-b'));
        $noScope  = $this->save((new TimeEntry($this->user, null, '', null, new DateTimeImmutable('-5 hours'), new DateTimeImmutable('-4 hours'), 3600, TimeEntrySource::Manual))->describe('ACME'));
        $withTask = $this->save((new TimeEntry($this->user, 'task-1', 'Task', 'board-1', new DateTimeImmutable('-7 hours'), new DateTimeImmutable('-6 hours'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a'));

        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        self::assertFalse($timers->allowsEntriesWithoutTask());
        $from   = new DateTimeImmutable('-1 day');
        $to     = new DateTimeImmutable('+1 day');
        $target = (string) $this->user->getId();

        // Two scopes: each manager lists and counts only their own scope's entry without a task.
        $page = $timers->listEntriesPage($managerB, $from, $to, 1, 10, $target);
        self::assertSame($this->ids([$inB, $withTask]), $this->ids($page->entries));
        self::assertSame(2, $page->total, 'the count leaves the other scope out too');
        self::assertSame($this->ids([$inB, $withTask]), $this->ids($timers->listEntries($managerB, $from, $to, $target)));
        $pageA = $timers->listEntriesPage($managerA, $from, $to, 1, 10, $target);
        self::assertSame([$this->ids([$inA, $withTask]), 2], [$this->ids($pageA->entries), $pageA->total]);
        self::assertSame($this->ids([$inA, $inB, $noScope, $withTask]), $this->ids($timers->listEntriesPage($this->user, $from, $to, 1, 10)->entries));

        // A user with no entry without a task: the EXISTS read keeps the 1.35 listing (audit YM-M1).
        $other     = $this->createUser('other@example.com');
        $otherTask = $this->save((new TimeEntry($other, 'task-2', 'Task', 'board-1', new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a'));
        self::assertSame([$otherTask->getId()], $this->ids($timers->listEntriesPage($managerB, $from, $to, 1, 10, (string) $other->getId())->entries));

        // The back-fill never asks the provider for an entry without a task (audit YM-L2).
        $store = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(ScopeBackfillRepositoryInterface::class, $store);
        self::assertNotContains($noScope->getId(), $this->ids($store->findScopelessAfter('', 100)));

        $guard = self::getContainer()->get(TeamAccessGuard::class);
        self::assertInstanceOf(TeamAccessGuard::class, $guard);
        self::assertSame([false, false, true, false, true, true], [
            $guard->isEditGranted($managerB, $inA) || !$guard->isEditGranted($managerB, $inB),
            $guard->isBillableEditGranted($managerB, $inA),
            $guard->isEditGranted($managerA, $inA),
            $guard->isEditGranted($managerA, $noScope),
            $guard->isEditGranted($this->user, $noScope),
            $guard->isEditGranted($managerB, $withTask),
        ]);
    }

    private function save(TimeEntry $entry): TimeEntry
    {
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    /**
     * @param list<TimeEntry> $entries
     *
     * @return list<string>
     */
    private function ids(array $entries): array
    {
        $ids = array_map(static fn (TimeEntry $entry): string => $entry->getId(), $entries);
        sort($ids);

        return $ids;
    }
}
