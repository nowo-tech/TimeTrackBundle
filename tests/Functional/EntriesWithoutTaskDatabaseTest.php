<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\PersonalData\TimeTrackPersonalDataInterface;
use Nowo\TimeTrackBundle\Query\RunningTimerQueryInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TaskEffortRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryListing;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\ScopeKeyCollation;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function sort;

/**
 * Since 1.36.0 (Beacon spec 250, `entries.allow_without_task`) on the Doctrine stores (SQLite here; MySQL 8.4,
 * MariaDB 11.4 and PostgreSQL 16 in the database jobs): timers and entries without a task are stored with a NULL task,
 * read back by every read port, never reached through task or board keys, and listed to anyone but their owner only in
 * that person's tenant scopes, byte for byte (also on a folding collation).
 */
final class EntriesWithoutTaskDatabaseTest extends FunctionalTestCase
{
    private AppUser $admin;

    private AppUser $otherAdmin;

    protected static function timeTrackConfig(): array
    {
        return [
            'manual_entries' => ['enabled' => true],
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'entries'        => ['allow_without_task' => true],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin      = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->otherAdmin = $this->createUser('other@example.com', ['ROLE_ADMIN']);
        $scopes           = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a', 'org-b'], (string) $this->admin->getId() => ['org-a'], (string) $this->otherAdmin->getId() => ['org-c']];
    }

    public function testATimerAndAManualEntryWithoutATaskAreStoredAndReadBack(): void
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $timer  = $timers->start($this->user, null, ClientType::Web, [], 'Call with ACME', 'org-b');
        $userId = (string) $this->user->getId();

        $running = $this->service(RunningTimerQueryInterface::class)->runningOf($userId);
        self::assertSame([true, '', null, 'Call with ACME', 'org-b'], [$running?->timer->withoutTask, $running?->timer->taskId, $running?->timer->taskIdOrNull(), $running?->timer->description, $running?->timer->scopeKey]);
        $export = $this->service(TimeTrackPersonalDataInterface::class)->export($userId);
        self::assertSame(['withoutTask' => true, 'description' => 'Call with ACME', 'scopeKey' => 'org-b'], ['withoutTask' => $export['runningTimer']['withoutTask'] ?? null, 'description' => $export['runningTimer']['description'] ?? null, 'scopeKey' => $export['runningTimer']['scopeKey'] ?? null]);

        $this->entityManager()->clear();
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);
        $entry = $timers->stop($user);
        self::assertNotSame('', $timer->getId());
        self::assertSame([null, 'Call with ACME', 'org-b'], [$entry?->taskIdOrNull(), $entry?->getDescription(), $entry?->getScopeKey()]);
        $manual = $this->service(ManualEntryService::class)->create($user, null, new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), TimeEntrySource::Manual, description: 'Visit ACME');

        $connection = $this->entityManager()->getConnection();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE task_id IS NULL'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));

        $records = $this->service(TimeEntryQueryInterface::class)->list(new TimeEntryCriteria(ownerIds: [$userId]));
        self::assertCount(2, $records);
        foreach ($records as $record) {
            self::assertSame([true, '', null], [$record->entry->withoutTask, $record->entry->taskId, $record->entry->taskIdOrNull()]);
        }
        $rows = $this->service(TimeTrackPersonalDataInterface::class)->export($userId)['entries'];
        self::assertSame([true, true], array_map(static fn (array $row): mixed => $row['withoutTask'] ?? false, $rows));

        // Never reached through a task or a board key.
        self::assertSame(0, $this->service(TimeEntryQueryInterface::class)->count(new TimeEntryCriteria(taskIds: ['task-1'])));
        self::assertSame(0, $this->service(TimeEntryQueryInterface::class)->count(new TimeEntryCriteria(boardIds: ['board-1'])));
        self::assertSame(2, $this->service(TimeEntryQueryInterface::class)->count(new TimeEntryCriteria(scopeKeys: ['org-a', 'org-b'])));
        $effort = $this->service(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(TaskEffortRepositoryInterface::class, $effort);
        self::assertSame([], $effort->loggedSecondsByTask(['task-1', '']));
        self::assertSame($manual->getId(), $this->service(TimeEntryQueryInterface::class)->find($manual->getId())?->entry->id);
    }

    public function testAnotherOwnersEntriesWithoutATaskAreListedOnlyInTheViewersScopesByteForByte(): void
    {
        ScopeKeyCollation::fold($this->entityManager()->getConnection());
        $inScope    = $this->entry($this->user, 'org-a');
        $lookAlike  = $this->entry($this->user, 'Org-a');
        $otherScope = $this->entry($this->user, 'org-b');
        $noScope    = $this->entry($this->user, null);
        $withTask   = $this->save(new TimeEntry($this->user, 'task-1', 'Task', 'board-1', new DateTimeImmutable('-5 hours'), new DateTimeImmutable('-4 hours'), 3600, TimeEntrySource::Web));
        $ownerless  = $this->entry(null, 'org-a');
        $this->entry(null, 'Org-a'); // An ownerless look-alike: the SQL may match it on a folding collation, the PHP check never.
        $timers = $this->service(TimerService::class);
        $from   = new DateTimeImmutable('-2 days');
        $to     = new DateTimeImmutable('+1 day');

        $page = $timers->listEntriesPage($this->admin, $from, $to, 1, 10, (string) $this->user->getId());
        self::assertSame($this->sorted([$inScope, $withTask]), $this->sorted($page->entries));
        self::assertSame(2, $page->total);
        self::assertCount(1, $timers->listEntriesPage($this->admin, $from, $to, 2, 1, (string) $this->user->getId())->entries);
        self::assertSame($this->sorted([$withTask]), $this->sorted($timers->listEntries($this->otherAdmin, $from, $to, (string) $this->user->getId())));
        self::assertCount(5, $timers->listEntries($this->user, $from, $to), 'the owner lists every own entry');

        // A list-query listener that also lists the ownerless entries of org-a.
        $dispatcher = $this->service(EventDispatcherInterface::class);
        $listener   = function (TimeEntryListQueryEvent $event): void {
            $event->setUserIds([(string) $this->user->getId()]);
            $event->includeOwnerless(['org-a']);
        };
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, $listener);
        self::assertSame($this->sorted([$inScope, $withTask, $ownerless]), $this->sorted($timers->listEntriesPage($this->admin, $from, $to, 1, 10)->entries));
        self::assertSame(3, $timers->listEntriesPage($this->admin, $from, $to, 1, 1)->total);
        $dispatcher->removeListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, $listener);

        // The store's own listing (a viewer without scopes keeps only its own entries without a task).
        $store = $this->service(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $store);
        $listing = new TimeEntryListing([(string) $this->user->getId()], $from, $to, true, false, null, (string) $this->admin->getId(), []);
        self::assertSame($this->sorted([$withTask]), $this->sorted($store->findListingPage($listing, 0, 10)));
        self::assertSame(1, $store->countListing($listing));

        // Editing follows the same tenant rule; the look-alike never counts as org-a.
        $guard = $this->service(TeamAccessGuard::class);
        self::assertSame([true, false, false, false, true], [$guard->isEditGranted($this->admin, $inScope), $guard->isEditGranted($this->admin, $lookAlike), $guard->isEditGranted($this->admin, $otherScope), $guard->isEditGranted($this->admin, $noScope), $guard->isEditGranted($this->user, $lookAlike)]);
    }

    private function entry(?AppUser $user, ?string $scopeKey): TimeEntry
    {
        $entry = (new TimeEntry($user, null, '', null, new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), 3600, TimeEntrySource::Manual))->describe('ACME');
        $entry->assignScopeKey($scopeKey);

        return $this->save($entry);
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
    private function sorted(array $entries): array
    {
        $ids = array_map(static fn (TimeEntry $entry): string => $entry->getId(), $entries);
        sort($ids);

        return $ids;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
