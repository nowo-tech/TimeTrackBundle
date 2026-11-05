<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedEntryOwnerMismatchException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryDeleter;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Import\InvalidImportedTimeEntryException;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RacingImportedTimeEntryRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_filter;
use function array_map;
use function array_values;
use function iterator_to_array;
use function str_repeat;

/**
 * Since 1.11: imported time entries — writer, repository, read-only and delete rules, exclusions and masking against
 * SQLite.
 */
final class ImportedEntriesTest extends FunctionalTestCase
{
    private const SOURCE = 'clickup:dir-1';

    private EventDispatcher $dispatcher;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new EventDispatcher();
        $this->events     = [];
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_IMPORTED, function (TimeEntryImportedEvent $event): void {
            $this->events[] = $event;
        });
        $this->dispatcher->addListener(TimeTrackEvents::TIME_ENTRIES_REMOVED, function (TimeEntriesRemovedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testTheSchemaHasTheExternalIdentityAndTheEndedAtIndex(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(TimeEntry::class);
        self::assertSame(['ended_at', 'id'], $metadata->table['indexes']['time_track_entries_ended_idx']['columns'] ?? null);
        self::assertSame(['source'], $metadata->table['indexes']['idx_time_track_entries_source']['columns'] ?? null);
        self::assertSame(['external_source', 'external_id'], $metadata->table['uniqueConstraints']['uniq_time_track_entries_external']['columns'] ?? null);
        self::assertSame('text', $metadata->getFieldMapping('description')->type);
        self::assertNull($metadata->getFieldMapping('description')->length);
    }

    public function testUpsertCreatesUpdatesAndLeavesUnchangedEntriesAlone(): void
    {
        $writer = $this->writer();
        $long   = str_repeat('Long description. ', 10_000);

        $created = $writer->upsert($this->user, $this->data('e-1', description: $long));
        self::assertSame(ImportedTimeEntryOutcome::Created, $created->outcome);
        $this->entityManager()->clear();

        $stored = $this->repository()->findByExternal(self::SOURCE, 'e-1');
        self::assertInstanceOf(TimeEntry::class, $stored);
        self::assertSame(TimeEntrySource::Import, $stored->getSource());
        self::assertSame(trim($long), $stored->getDescription(), 'the description is kept in full');

        self::assertSame(ImportedTimeEntryOutcome::Unchanged, $writer->upsert($this->user, $this->data('e-1', description: $long))->outcome);
        $updated = $writer->upsert($this->user, $this->data('e-1', seconds: 1800, description: $long));
        self::assertSame(ImportedTimeEntryOutcome::Updated, $updated->outcome);
        self::assertSame(1800, $updated->entry->getDurationSeconds());

        self::assertCount(2, $this->events);
        self::assertInstanceOf(TimeEntryImportedEvent::class, $this->events[1]);
        self::assertSame(3600, $this->events[1]->getPreviousDurationSeconds());
        self::assertNull($this->events[1]->getPreviousTaskId());
    }

    public function testUpsertManyValidatesEverythingFirstAndFlushesOnce(): void
    {
        $writer = $this->writer();
        $result = $writer->upsertMany([[$this->user, $this->data('e-1')], [$this->user, $this->data('e-2', task: 'task-2')]]);
        self::assertSame([2, 0, 0], [$result->created, $result->updated, $result->unchanged]);

        try {
            $writer->upsertMany([[$this->user, $this->data('e-3')], [$this->user, $this->data('bad id!')]]);
            self::fail('expected a refusal');
        } catch (InvalidImportedTimeEntryException $exception) {
            self::assertSame('externalId', $exception->field);
        }
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-3'), 'nothing of a refused batch is written');

        $rows = array_map(fn (int $i): array => [$this->user, $this->data('x-' . $i)], range(1, ImportedTimeEntryWriter::MAX_BATCH + 1));
        $this->expectException(InvalidImportedTimeEntryException::class);
        $writer->upsertMany($rows);
    }

    public function testTheOwnerOfAnImportedEntryNeverChanges(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $other = $this->createUser('other@example.com');

        try {
            $writer->upsert($other, $this->data('e-1', seconds: 60));
            self::fail('expected the owner mismatch');
        } catch (ImportedEntryOwnerMismatchException) {
        }
        self::assertSame(3600, $this->repository()->findByExternal(self::SOURCE, 'e-1')?->getDurationSeconds());

        // Re-association is remove + upsert.
        self::assertSame(1, $writer->removeForUser(self::SOURCE, $this->user));
        self::assertSame(ImportedTimeEntryOutcome::Created, $writer->upsert($other, $this->data('e-1'))->outcome);
    }

    /**
     * @return iterable<string, array{0: ImportedTimeEntryData, 1: string, 2: string}>
     */
    public static function invalidData(): iterable
    {
        $start = new DateTimeImmutable('2026-01-01 10:00:00');
        $end   = new DateTimeImmutable('2026-01-01 11:00:00');
        yield 'source' => [new ImportedTimeEntryData('ClickUp', 'e', 'task-1', 'T', null, $start, $end, 60), 'externalSource', 'pattern'];
        yield 'task' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task 1', 'T', null, $start, $end, 60), 'taskId', 'pattern'];
        yield 'board' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task-1', 'T', 'b/1', $start, $end, 60), 'boardId', 'pattern'];
        yield 'end before start' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task-1', 'T', null, $end, $start, 60), 'endedAt', 'before_start'];
        yield 'zero' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task-1', 'T', null, $start, $end, 0), 'durationSeconds', 'not_positive'];
        yield 'longer than the span' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task-1', 'T', null, $start, $end, 3661), 'durationSeconds', 'longer_than_span'];
        yield 'over 31 days' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task-1', 'T', null, $start, $start->modify('+32 days'), 3600), 'durationSeconds', 'max_entry_seconds'];
        yield 'title not UTF-8' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task-1', "T\xC3", null, $start, $end, 60), 'taskTitle', 'encoding'];
        yield 'description not UTF-8' => [new ImportedTimeEntryData(self::SOURCE, 'e', 'task-1', 'T', null, $start, $end, 60, null, "ok\xFF"), 'description', 'encoding'];
    }

    #[DataProvider('invalidData')]
    public function testInvalidDataIsRefused(ImportedTimeEntryData $data, string $field, string $rule): void
    {
        try {
            $this->writer()->validate($data);
            self::fail('expected a refusal');
        } catch (InvalidImportedTimeEntryException $exception) {
            self::assertSame([$field, $rule], [$exception->field, $exception->rule]);
        }
    }

    public function testExactlyThirtyOneDaysIsAccepted(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');
        $data  = $this->writer()->validate($this->data('e', seconds: ImportedTimeEntryWriter::DEFAULT_MAX_ENTRY_SECONDS, start: $start, end: $start->modify('+31 days')));
        self::assertSame(2_678_400, $data->durationSeconds);
    }

    public function testTheTitleIsCleanedToOneLine(): void
    {
        self::assertSame('Fix login page', $this->writer()->validate($this->data('e', title: "\u{202E}Fix\r\nlogin\u{0007}page "))->taskTitle);
        // U+2028 / U+2029 are line breaks too; a run of breaks and the blanks around it is one space (FJ-I5).
        self::assertSame('Fix login page now', $this->writer()->validate($this->data('e', title: "\u{2029}Fix\u{2028}login \n\n\u{2029} page\u{2028}\u{2029}now\u{2028}"))->taskTitle);
    }

    public function testTwoRowsForOneIdentityInABatchAreRefused(): void
    {
        try {
            $this->writer()->upsertMany([[$this->user, $this->data('e-1')], [$this->user, $this->data('e-1', seconds: 60)]]);
            self::fail('expected a refusal');
        } catch (InvalidImportedTimeEntryException $exception) {
            self::assertSame(['rows', 'duplicate'], [$exception->field, $exception->rule]);
        }
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-1'));
        self::assertSame(2, $this->writer()->upsertMany([[$this->user, $this->data('e-1')], [$this->user, $this->data('e-1', source: 'clickup:dir-2')]])->created, 'the same id in another source is another identity');
    }

    public function testAConcurrentInsertIsReadAgainAndBecomesAnUpdate(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $this->events = [];

        $racing = new RacingImportedTimeEntryRepository($this->repository());
        $racing->staleReads(1);
        $result = (new ImportedTimeEntryWriter($racing, $this->dispatcher))->upsert($this->user, $this->data('e-1', seconds: 1800));

        self::assertSame(ImportedTimeEntryOutcome::Updated, $result->outcome);
        self::assertSame(2, $racing->saveAllCalls, 'the unique violation is retried once');
        $this->entityManager()->clear();
        self::assertSame(1800, $this->repository()->findByExternal(self::SOURCE, 'e-1')?->getDurationSeconds());
        self::assertSame(1, $this->repository()->countByUsersAndPeriod([(string) $this->user->getId()], new DateTimeImmutable('2025-12-31'), new DateTimeImmutable('2026-01-02')));
        self::assertCount(1, $this->events);

        // A concurrent insert for another owner is still refused after the re-read.
        $other  = $this->createUser('other@example.com');
        $racing = new RacingImportedTimeEntryRepository($this->repository());
        $racing->staleReads(1);
        $this->expectException(ImportedEntryOwnerMismatchException::class);
        (new ImportedTimeEntryWriter($racing, $this->dispatcher))->upsert($other, $this->data('e-1', seconds: 60));
    }

    public function testAConcurrentInsertInABatchWithNewRowsCreatesThemWithTheOwnerReattached(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $this->events = [];

        $racing = new RacingImportedTimeEntryRepository($this->repository());
        $racing->staleReads(1);
        $result = (new ImportedTimeEntryWriter($racing, $this->dispatcher))->upsertMany([
            [$this->user, $this->data('e-1', seconds: 1800)],
            [$this->user, $this->data('e-2', task: 'task-2')],
            [$this->user, $this->data('e-3', task: 'task-3')],
        ]);

        self::assertSame([1, 2, 0], [$result->updated, $result->created, $result->unchanged]);
        self::assertSame(2, $racing->saveAllCalls, 'the unique violation is retried once');
        self::assertCount(3, $this->events);
        $this->entityManager()->clear();
        self::assertSame(1800, $this->repository()->findByExternal(self::SOURCE, 'e-1')?->getDurationSeconds());
        foreach (['e-2', 'e-3'] as $id) {
            $owner = $this->repository()->findByExternal(self::SOURCE, $id)?->getUser();
            self::assertInstanceOf(AppUser::class, $owner);
            self::assertSame($this->user->getId(), $owner->getId());
        }
    }

    public function testWithoutARegistryTheRaceFailsClosed(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));

        // No ManagerRegistry: the failed flush leaves the entity manager closed, so the writer rethrows.
        $racing = new RacingImportedTimeEntryRepository(new DoctrineOrmTimeEntryRepository($this->entityManager()));
        $racing->staleReads(1);
        try {
            (new ImportedTimeEntryWriter($racing))->upsertMany([[$this->user, $this->data('e-1', seconds: 1800)], [$this->user, $this->data('e-2')]]);
            self::fail('expected the unique violation');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertSame(1, $racing->saveAllCalls);
        self::assertFalse($this->entityManager()->isOpen());

        $this->resetEntityManager();
        self::assertSame(3600, $this->repository()->findByExternal(self::SOURCE, 'e-1')?->getDurationSeconds(), 'nothing was written');
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-2'));
    }

    public function testInsideAHostTransactionTheRaceFailsClosed(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));

        $connection = $this->entityManager()->getConnection();
        $connection->beginTransaction();
        $racing = new RacingImportedTimeEntryRepository($this->repository());
        $racing->staleReads(1);
        try {
            (new ImportedTimeEntryWriter($racing))->upsertMany([[$this->user, $this->data('e-1', seconds: 1800)], [$this->user, $this->data('e-2')]]);
            self::fail('expected the unique violation');
        } catch (UniqueConstraintViolationException) {
            self::assertTrue($connection->isTransactionActive(), 'the host transaction is left to the host');
        } finally {
            $connection->rollBack();
        }
        self::assertSame(1, $racing->saveAllCalls);
        self::assertTrue($this->repository()->canRetryAfterFailedSave());
        self::assertSame(3600, $this->repository()->findByExternal(self::SOURCE, 'e-1')?->getDurationSeconds());
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-2'));
    }

    public function testAttachUserReturnsAManagedUser(): void
    {
        $repository = $this->repository();
        self::assertSame($this->user, $repository->attachUser($this->user), 'already managed');

        $memory = new InMemoryUser('memory', null);
        self::assertSame($memory, $repository->attachUser($memory), 'not an entity of the store');

        $unsaved = new AppUser('unsaved@example.com', 'secret', ['ROLE_USER']);
        self::assertSame($unsaved, $repository->attachUser($unsaved), 'no identifier yet');

        $this->entityManager()->clear();
        $attached = $repository->attachUser($this->user);
        self::assertNotSame($this->user, $attached);
        self::assertInstanceOf(AppUser::class, $attached);
        self::assertSame($this->user->getId(), $attached->getId());
        self::assertTrue($this->entityManager()->contains($attached));
    }

    public function testTimersIdleAndSwitchNeverTouchImportedEntries(): void
    {
        $clock  = new MockClock('2026-11-02 10:00:00');
        $memory = new InMemoryTimeEntryRepository();
        $import = TimeEntry::imported($this->user, $this->data('e-1', seconds: 7200, start: new DateTimeImmutable('2026-11-02 09:00:00'), end: new DateTimeImmutable('2026-11-02 11:00:00')));
        $memory->save($import);
        $dispatcher = new EventDispatcher();
        $fired      = [];
        foreach ([TimeTrackEvents::TIME_ENTRY_IMPORTED, TimeTrackEvents::TIME_ENTRIES_REMOVED] as $name) {
            $dispatcher->addListener($name, static function (object $event) use (&$fired): void {
                $fired[] = $event;
            });
        }
        $service = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $memory, $dispatcher, $clock);

        $service->start($this->user, 'task-1', ClientType::Desktop);
        $clock->sleep(1800);
        $service->switchTo($this->user, 'task-2', ClientType::Desktop);
        $clock->sleep(1800);
        $service->resolveIdle($this->user, new DateTimeImmutable('2026-11-02 10:40:00'), new DateTimeImmutable('2026-11-02 10:50:00'), IdleResolution::Discard);
        $service->stop($this->user);

        $entries = $memory->findByUserAndPeriod((string) $this->user->getId(), new DateTimeImmutable('2026-11-02 00:00:00'), new DateTimeImmutable('2026-11-03 00:00:00'));
        $stored  = array_values(array_filter($entries, static fn (TimeEntry $e): bool => $e->isReadOnly()));
        self::assertSame([$import], $stored);
        self::assertSame(7200, $import->getDurationSeconds());
        self::assertSame('2026-11-02 11:00:00', $import->getEndedAt()->format('Y-m-d H:i:s'));
        self::assertCount(2, array_filter($entries, static fn (TimeEntry $e): bool => $e->getSource()->isLive()), 'start → switch → stop wrote two live entries');
        self::assertSame([], $fired);
    }

    public function testValidateFallsBackToTheTaskIdForAnEmptyTitleAndCutsLongTitles(): void
    {
        $writer = $this->writer();
        self::assertSame('task-1', $writer->validate($this->data('e', title: '   '))->taskTitle);
        self::assertSame(255, mb_strlen($writer->validate($this->data('e', title: str_repeat('é', 300)))->taskTitle));
        self::assertNull($writer->validate($this->data('e', description: " \n "))->description);
    }

    public function testRemovalsByEveryScopeReportSecondsPerTask(): void
    {
        $writer = $this->writer();
        $other  = $this->createUser('other@example.com');
        $writer->upsertMany([
            [$this->user, $this->data('e-1')],
            [$this->user, $this->data('e-2', task: 'task-2')],
            [$other, $this->data('e-3', task: 'task-2')],
            [$other, $this->data('e-4', board: 'board-2')],
            [$other, $this->data('e-5', source: 'clickup:dir-2')],
        ]);
        $this->events = [];

        self::assertSame(1, $writer->remove(self::SOURCE, 'e-1', 'actor-1'));
        self::assertSame(0, $writer->remove(self::SOURCE, 'missing'));
        self::assertSame(0, $writer->removeForTasks(self::SOURCE, []));
        self::assertSame(2, $writer->removeForTasks(self::SOURCE, ['task-2']));
        self::assertSame(1, $writer->removeForBoard(self::SOURCE, 'board-2'));
        self::assertSame(0, $writer->removeForSource(self::SOURCE));
        self::assertSame(1, $writer->removeForSource('clickup:dir-2'));

        self::assertCount(4, $this->events);
        $tasks = $this->events[1];
        self::assertInstanceOf(TimeEntriesRemovedEvent::class, $tasks);
        self::assertSame(ImportedTimeEntryScope::TASKS, $tasks->getScope());
        self::assertSame(['task-2' => 7200], $tasks->getSecondsByTask());
        self::assertCount(2, $tasks->getUserIds());
        self::assertSame('actor-1', $this->events[0]->getActorId());

        $this->expectException(InvalidImportedTimeEntryException::class);
        $writer->removeForSource('clickup');
    }

    public function testRemovalWorksInBatchesAndListingBySourceIsKeysetPaged(): void
    {
        $writer = $this->writer();
        $writer->upsertMany(array_map(fn (int $i): array => [$this->user, $this->data('e-' . $i)], range(1, 5)));
        $repository = $this->repository();

        self::assertCount(5, iterator_to_array($repository->findByExternalSource(self::SOURCE, 2), false));
        self::assertSame(5, $repository->removeImported(ImportedTimeEntryScope::source(self::SOURCE), 2)->count);
        self::assertSame(0, $repository->removeImported(ImportedTimeEntryScope::tasks(self::SOURCE, []))->count);
    }

    public function testImportedEntriesNeverClampALiveStartAndAreExcludedFromLiveQueries(): void
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $live = new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), 3600, TimeEntrySource::Web);
        $repository->save($live);
        $this->writer()->upsert($this->user, $this->data('e-1', start: new DateTimeImmutable('-90 minutes'), end: new DateTimeImmutable('-30 minutes')));
        $userId = (string) $this->user->getId();

        self::assertSame($live->getEndedAt()->getTimestamp(), $repository->findLatestEndedAt($userId)?->getTimestamp());
        self::assertSame([$live->getId()], array_map(static fn (TimeEntry $e): string => $e->getId(), $repository->findLiveByUserAndPeriod($userId, new DateTimeImmutable('-1 day'), new DateTimeImmutable())));
        self::assertSame(1, $repository->countLiveByUsersAndPeriod([$userId], new DateTimeImmutable('-1 day'), new DateTimeImmutable()));
        self::assertSame(0, $repository->countLiveByUsersAndPeriod([], new DateTimeImmutable('-1 day'), new DateTimeImmutable()));
        self::assertSame([], $repository->findLivePageByUsersAndPeriod([], new DateTimeImmutable('-1 day'), new DateTimeImmutable(), 0, 10));
        self::assertCount(1, $repository->findLivePageByUsersAndPeriod([$userId], new DateTimeImmutable('-1 day'), new DateTimeImmutable(), 0, 10));
        self::assertSame(2, $repository->countByUsersAndPeriod([$userId], new DateTimeImmutable('-1 day'), new DateTimeImmutable()));
    }

    public function testListsIncludeImportedEntriesUnlessAListenerExcludesThem(): void
    {
        $repository = $this->repository();
        $repository->save(new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), 3600, TimeEntrySource::Web));
        $this->writer()->upsert($this->user, $this->data('e-1', start: new DateTimeImmutable('-90 minutes'), end: new DateTimeImmutable('-30 minutes')));
        $from = new DateTimeImmutable('-1 day');
        $to   = new DateTimeImmutable();

        $dispatcher = new EventDispatcher();
        $tasks      = new StubTaskProvider();
        $timers     = new InMemoryActiveTimerRepository();
        $service    = new TimerService($tasks, $timers, $repository, $dispatcher);
        self::assertSame(2, $service->listEntriesPage($this->user, $from, $to, 1, 10)->total);

        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_LIST_QUERY, static function (TimeEntryListQueryEvent $event): void {
            $event->setIncludeImported(false);
        });
        self::assertSame(1, $service->listEntriesPage($this->user, $from, $to, 1, 10)->total);
        self::assertSame(0, $service->listEntriesPage($this->user, $from, $to, 1, 10, 'nobody')->total);
        self::assertCount(1, $service->listEntries($this->user, $from, $to));

        // A store without the imported-entry interface filters in memory.
        $memory = new InMemoryTimeEntryRepository();
        $memory->save(new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('-3 hours'), new DateTimeImmutable('-2 hours'), 3600, TimeEntrySource::Web));
        $memory->save(TimeEntry::imported($this->user, $this->data('e-9', start: new DateTimeImmutable('-90 minutes'), end: new DateTimeImmutable('-30 minutes'))));
        $inMemory = new TimerService($tasks, $timers, $memory, $dispatcher);
        self::assertSame(1, $inMemory->listEntriesPage($this->user, $from, $to, 1, 10)->total);
    }

    public function testHiddenTaskEntryIdsMaskImportedEntriesOfTasksTheOwnerCannotSee(): void
    {
        /** @var TimerService $service */
        $service = self::getContainer()->get(TimerService::class);
        $visible = TimeEntry::imported($this->user, $this->data('e-1'));
        $hidden  = TimeEntry::imported($this->user, $this->data('e-2', task: 'task-9'));
        $again   = TimeEntry::imported($this->user, $this->data('e-3', task: 'task-9'));
        $live    = new TimeEntry($this->user, 'task-9', 'T', null, new DateTimeImmutable('-1 hour'), new DateTimeImmutable(), 3600, TimeEntrySource::Web);
        $orphan  = TimeEntry::imported(new stdClass(), $this->data('e-4'));

        self::assertSame([$hidden->getId() => true, $again->getId() => true, $orphan->getId() => true], $service->hiddenTaskEntryIds([$visible, $hidden, $again, $live, $orphan]));
    }

    public function testClientApiAndReportsShowImportedEntriesReadOnlyWithMaskedTitles(): void
    {
        $this->writer()->upsertMany([
            [$this->user, $this->data('e-1', title: 'Visible title', start: new DateTimeImmutable('-3 hours'), end: new DateTimeImmutable('-2 hours'))],
            [$this->user, $this->data('e-2', task: 'task-9', title: 'Secret title', start: new DateTimeImmutable('-90 minutes'), end: new DateTimeImmutable('-30 minutes'))],
        ]);
        $token = $this->login();

        $body = $this->api('GET', '/api/time-track/entries', null, $token);
        self::assertIsArray($body);
        $titles = array_column($body['entries'], 'taskTitle');
        self::assertContains('Visible title', $titles);
        self::assertContains(TimeEntry::HIDDEN_TASK_TITLE, $titles);
        self::assertNotContains('Secret title', $titles);
        self::assertTrue($body['entries'][0]['readOnly']);
        self::assertSame('import', $body['entries'][0]['source']);

        $this->client->request('GET', '/api/time-track/export?format=csv', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Secret title', $csv);
        $json = $this->api('GET', '/api/time-track/export?format=json', null, $token);
        self::assertStringNotContainsString('Secret title', (string) json_encode($json));

        $this->client->loginUser($this->user, 'main');
        $this->client->request('GET', '/tools/time-track/reports');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Visible title', $html);
        self::assertStringContainsString('Task not visible', $html);
        self::assertStringNotContainsString('Secret title', $html);
        self::assertStringContainsString('Imported', $html);
    }

    public function testImportedEntriesAreNeverEditableAndOnlyDeletableWhenAListenerGrantsIt(): void
    {
        $entry = $this->writer()->upsert($this->user, $this->data('e-1'))->entry;
        $live  = new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('-1 hour'), new DateTimeImmutable(), 3600, TimeEntrySource::Web);
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event): void {
            $event->grant();
        });
        $granting = new TeamAccessGuard(new NullTeamContextProvider(), $dispatcher, ['ROLE_ADMIN'], true, true);
        $denying  = new TeamAccessGuard(new NullTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true);

        self::assertFalse($granting->canEditEntry($admin, $entry), 'no listener can make an imported entry editable');
        self::assertTrue($granting->canEditEntry($this->user, $live));
        self::assertFalse($denying->canDeleteImportedEntry($this->user, $entry), 'denied by default, even for the owner');
        self::assertFalse($granting->canDeleteImportedEntry($admin, $live), 'live entries are never deleted through this path');
        self::assertTrue($granting->canDeleteImportedEntry($admin, $entry));

        try {
            (new ImportedTimeEntryDeleter($denying, $this->writer()))->delete($admin, $entry);
            self::fail('expected a denial');
        } catch (AccessDeniedException) {
        }
        try {
            (new ImportedTimeEntryDeleter($granting, $this->writer()))->delete($admin, $live);
            self::fail('expected a denial');
        } catch (AccessDeniedException) {
        }
        (new ImportedTimeEntryDeleter($granting, $this->writer()))->delete($admin, $entry);
        self::assertNull($this->repository()->findByExternal(self::SOURCE, 'e-1'));
        $removed = $this->events[1];
        self::assertInstanceOf(TimeEntriesRemovedEvent::class, $removed);
        self::assertSame((string) $admin->getId(), $removed->getActorId());
    }

    public function testABackdatedStartIsNotClampedByAnImportedEntryInAPlainStore(): void
    {
        $memory = new InMemoryTimeEntryRepository();
        $memory->save(TimeEntry::imported($this->user, $this->data('e-1', seconds: 900, start: new DateTimeImmutable('-20 minutes'), end: new DateTimeImmutable('-5 minutes'))));
        $service = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), $memory, new EventDispatcher());

        $result = $service->startAt($this->user, 'task-1', ClientType::Desktop, [], new DateTimeImmutable('-30 minutes'), new BackdateLimits(3600));
        self::assertInstanceOf(ClampedTime::class, $result['startTime']);
        self::assertFalse($result['startTime']->clamped);
    }

    public function testTheWriterRequiresAnImportCapableRepository(): void
    {
        $this->expectException(LogicException::class);
        new ImportedTimeEntryWriter(new InMemoryTimeEntryRepository());
    }

    public function testTheContainerWiresTheRepositoryAlias(): void
    {
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, self::getContainer()->get(TimeEntryRepositoryInterface::class));
    }

    private function resetEntityManager(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $registry->resetManager();
    }

    private function writer(): ImportedTimeEntryWriter
    {
        return new ImportedTimeEntryWriter($this->repository(), $this->dispatcher);
    }

    private function repository(): ImportedTimeEntryRepositoryInterface
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(ImportedTimeEntryRepositoryInterface::class, $repository);

        return $repository;
    }

    private function data(
        string $id,
        string $task = 'task-1',
        int $seconds = 3600,
        ?string $description = 'Imported from ClickUp',
        string $title = 'Implement login API',
        ?string $board = 'board-1',
        string $source = self::SOURCE,
        ?DateTimeImmutable $start = null,
        ?DateTimeImmutable $end = null,
    ): ImportedTimeEntryData {
        $start ??= new DateTimeImmutable('2026-01-01 10:00:00');
        $end ??= $start->modify('+1 hour');

        return new ImportedTimeEntryData($source, $id, $task, $title, $board, $start, $end, $seconds, true, $description);
    }
}
