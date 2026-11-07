<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntriesMovedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedEntryOwnerMismatchException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryClaim;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryMoveResult;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Import\InvalidImportedTimeEntryException;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\HostFlushListener;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\RacingImportedTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function sprintf;

/**
 * Since 1.16: moving imported entries between a user and a person by source, without deleting time, the per-row mismatch and
 * the listener's order and guard (HA-L18, HA-L19). Runs on every database of the functional suite.
 */
final class ImportedOwnerMoveTest extends FunctionalTestCase
{
    private const SOURCE = 'clickup:dir-1';

    /** @var list<TimeEntriesMovedEvent> */
    private array $events = [];

    public function testAUsersEntriesMoveToAPersonAndBackKeepingEverything(): void
    {
        $writer = $this->writer();
        $writer->upsert(new ImportedTimeEntryClaim($this->user, 'p-1', 'org-a'), $this->data('e-1', 'Secret note'));
        $writer->upsert(new ImportedTimeEntryClaim($this->user, 'p-1', 'org-a'), $this->data('e-2', null, 1800));
        $this->entityManager()->clear();
        $before = $this->reload('e-1');
        $id     = $before->getId();

        $result = $writer->moveToPerson(self::SOURCE, $this->managedUser(), new ImportedTimeEntryPerson('p-1', "Ana\nRuiz", 'org-a'), actorId: 'manager-1');
        self::assertSame([2, 0, 0, 5400], [$result->moved, $result->keptLocked, $result->refusedScope, $result->secondsMoved]);
        $this->entityManager()->clear();
        $entry = $this->reload('e-1');
        self::assertNull($entry->getUser());
        self::assertSame('Ana Ruiz', $entry->getPersonName());
        self::assertSame('p-1', $entry->getPersonRef());
        self::assertSame($id, $entry->getId());
        self::assertSame(3600, $entry->getDurationSeconds());
        self::assertSame('e-1', $entry->getExternalId());
        self::assertSame('Secret note', $entry->getDescription());
        self::assertCount(1, $this->events);
        self::assertSame((string) $this->user->getId(), $this->events[0]->getFromUserId());
        self::assertNull($this->events[0]->getFromPersonRef());
        self::assertSame('p-1', $this->events[0]->getToPersonRef());
        self::assertNull($this->events[0]->getToUserId());
        self::assertSame(self::SOURCE, $this->events[0]->getExternalSource());
        self::assertSame([2, 0, 0, 5400], [$this->events[0]->getMovedCount(), $this->events[0]->getKeptCount(), $this->events[0]->getRefusedCount(), $this->events[0]->getSecondsMoved()]);
        self::assertSame('manager-1', $this->events[0]->getActorId());

        $back = $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->managedUser(), 'p-1', 'org-a'));
        self::assertSame(2, $back->moved);
        $event = $this->lastEvent();
        self::assertSame('p-1', $event->getFromPersonRef());
        self::assertSame((string) $this->user->getId(), $event->getToUserId());
        $this->entityManager()->clear();
        $entry = $this->reload('e-1');
        self::assertSame($this->user->getId(), $entry->getUser() instanceof AppUser ? $entry->getUser()->getId() : null);
        self::assertNull($entry->getPersonRef());
        self::assertNull($entry->getPersonName());
        self::assertSame(2, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'nothing deleted or added');
    }

    public function testEntriesMoveBetweenUsers(): void
    {
        $other  = $this->createUser('other@example.com');
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));

        self::assertSame(1, $writer->moveBetweenUsers(self::SOURCE, $this->user, $other)->moved);
        $this->entityManager()->clear();
        $owner = $this->reload('e-1')->getUser();
        self::assertInstanceOf(AppUser::class, $owner);
        self::assertSame($other->getId(), $owner->getId());
        self::assertSame(0, $writer->moveBetweenUsers(self::SOURCE, $other, $other)->moved, 'to themselves');
    }

    public function testOnlyThisSourceThisOwnerAndTheNamedRowsMove(): void
    {
        $other  = $this->createUser('other@example.com');
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('mine'));
        $writer->upsert($this->user, $this->data('named'));
        $writer->upsert($other, $this->data('theirs'));
        $writer->upsert($this->user, $this->data('elsewhere', source: 'clickup:dir-2'));
        $live = new TimeEntry($this->user, 'task-1', 'Live', null, new DateTimeImmutable('2026-01-01 08:00:00'), new DateTimeImmutable('2026-01-01 09:00:00'), 3600, TimeEntrySource::Web);
        $this->entityManager()->persist($live);
        $this->entityManager()->flush();

        $person = new ImportedTimeEntryPerson('p-1', 'Ana');
        self::assertSame(1, $writer->moveToPerson(self::SOURCE, $this->user, $person, ['named', 'theirs'])->moved);
        self::assertSame(0, $writer->moveToPerson(self::SOURCE, $this->user, $person, [])->moved);
        $this->entityManager()->clear();
        self::assertNull($this->reload('named')->getUser());
        self::assertNotNull($this->reload('mine')->getUser());
        self::assertNotNull($this->reload('theirs')->getUser());
        self::assertNotNull($this->reload('elsewhere', 'clickup:dir-2')->getUser());
        self::assertCount(1, $this->events, 'no event when nothing happened');
        self::assertSame(1, $writer->moveToPerson(self::SOURCE, $this->managedUser(), $person)->moved, 'imported rows only: the live entry stays');
    }

    public function testAScopeThatIsNotExactlyTheTargetsStays(): void
    {
        $writer = $this->writer();
        $writer->upsert(new ImportedTimeEntryClaim($this->user, 'p-1', 'org-a'), $this->data('scoped'));
        $writer->upsert($this->user, $this->data('none'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-2', 'Luis', 'org-a'), $this->data('person'));

        $result = $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('p-1', 'Ana', 'org-b'));
        self::assertSame([0, 2], [$result->moved, $result->refusedScope], 'another tenant, and a row with none');
        $result = $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('p-1', 'Ana'));
        self::assertSame([1, 1], [$result->moved, $result->refusedScope], 'no scope moves only the row with none');
        $this->entityManager()->clear();
        self::assertNull($this->reload('none')->getScopeKey(), 'the scope is never rewritten');

        self::assertSame(1, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->managedUser(), 'p-2', 'org-b'))->refusedScope);
        self::assertSame(1, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->managedUser(), 'p-2'))->refusedScope);
        self::assertSame(1, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->managedUser(), 'p-2', 'org-a'))->moved);
    }

    public function testADeletedUsersEntriesOnlyGoBackToThatId(): void
    {
        $other  = $this->createUser('other@example.com');
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);
        $people->release($this->user, 'Ana');
        $ref = FormerPersonTimeEntries::personRefOf($this->user);

        self::assertSame(1, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($other, $ref))->refusedScope);
        $this->entityManager()->clear();
        self::assertNull($this->reload('e-1')->getUser());
        self::assertSame(1, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->managedUser(), $ref))->moved);
    }

    public function testAnonymisedEntriesNeverMove(): void
    {
        $writer = $this->writer();
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana'), $this->data('e-1'));
        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);
        $people->anonymisePerson('p-1');

        self::assertSame(0, $writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($this->user, 'p-1'))->moved);
        self::assertSame([], $this->events);
    }

    public function testBadInputWritesNothing(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        foreach ([
            fn (): mixed => $writer->moveToPerson('not a source', $this->user, new ImportedTimeEntryPerson('p-1')),
            fn (): mixed => $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('user:1')),
        ] as $call) {
            try {
                $call();
                self::fail('expected a refusal');
            } catch (InvalidImportedTimeEntryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->entityManager()->clear();
        self::assertNotNull($this->reload('e-1')->getUser());
    }

    public function testAFailureRollsTheWholeMoveBack(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('e-1'));
        $store = $this->store();

        try {
            $store->inOwnerMoveTransaction(function () use ($writer): never {
                $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('p-1', 'Ana'));

                throw new RuntimeException('after the move');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('after the move', $exception->getMessage());
        }
        $this->entityManager()->clear();
        self::assertNotNull($this->reload('e-1')->getUser());
    }

    public function testAStoreWithoutTheCapabilityIsRefused(): void
    {
        $writer = new ImportedTimeEntryWriter(new RacingImportedTimeEntryRepository($this->store()));

        $this->expectException(LogicException::class);
        $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('p-1'));
    }

    public function testManyEntriesMoveInBatches(): void
    {
        $writer = $this->writer();
        $rows   = [];
        for ($i = 0; $i < 500; ++$i) {
            $rows[] = [$this->user, $this->data(sprintf('e-%d', $i))];
        }
        $writer->upsertMany($rows);
        $writer->upsert($this->user, $this->data('e-last'));

        self::assertSame(501, $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('p-1'))->moved);
    }

    public function testAProviderMustListTheRowsScopeForTheNewUser(): void
    {
        $outsider = $this->createUser('outsider@example.com');
        $member   = $this->createUser('member@example.com');
        // The test provider answers from the board alone: `org-a` for this task, whoever asks.
        $scopes = new FixedScopeProvider([(string) $outsider->getId() => ['org-b'], (string) $member->getId() => ['org-a'], (string) $this->user->getId() => ['org-a']]);
        $writer = new ImportedTimeEntryWriter($this->store(), scopeProvider: $scopes);
        $writer->upsert($this->user, $this->data('owned'));
        $writer->upsertForPerson(new ImportedTimeEntryPerson('p-1', 'Ana', 'org-a'), $this->data('person'));

        self::assertSame([0, 1], $this->counts($writer->moveBetweenUsers(self::SOURCE, $this->user, $outsider)));
        self::assertSame([0, 1], $this->counts($writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($outsider, 'p-1'))));
        $this->assertMismatchOf('person', fn (): mixed => $writer->upsert(new ImportedTimeEntryClaim($outsider, 'p-1'), $this->data('person')));
        $this->entityManager()->clear();
        self::assertTrue($this->reload('person')->isOwnerless());

        $member = $this->entityManager()->find(AppUser::class, $member->getId());
        self::assertInstanceOf(AppUser::class, $member);
        self::assertSame([1, 0], $this->counts($writer->moveBetweenUsers(self::SOURCE, $this->managedUser(), $member)));
        self::assertSame([1, 0], $this->counts($writer->moveToUser(self::SOURCE, new ImportedTimeEntryClaim($member, 'p-1'))));
    }

    public function testSourcesAndIdsAreComparedExactly(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('x1'));
        $writer->upsert($this->user, $this->data('12abc'));
        $writer->upsert($this->user, $this->data('12'));
        $person = new ImportedTimeEntryPerson('p-1');

        self::assertSame(0, $writer->moveToPerson('clickup:DIR-1', $this->user, $person)->moved, 'another source, on any collation');
        self::assertSame(0, $writer->moveToPerson(self::SOURCE, $this->user, $person, ['X1'])->moved, 'another id, on any collation');
        foreach ([[12], ["x1\0"], [null], ['']] as $bad) {
            try {
                // @phpstan-ignore argument.type (what a host could pass)
                $writer->moveToPerson(self::SOURCE, $this->user, $person, $bad);
                self::fail('expected a refusal');
            } catch (InvalidImportedTimeEntryException $exception) {
                self::assertSame(['externalIds', 'pattern'], [$exception->field, $exception->rule]);
            }
        }
        self::assertSame(1, $writer->moveToPerson(self::SOURCE, $this->user, $person, ['12'])->moved, '12 is not 12abc');
        $this->entityManager()->clear();
        self::assertNotNull($this->reload('12abc')->getUser());
        self::assertNull($this->reload('12')->getUser());
    }

    public function testSettlingNeverOverwritesAnOwnerFoundMeanwhile(): void
    {
        $other  = $this->createUser('other@example.com');
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('to-user'));
        $writer->upsert($this->user, $this->data('to-person'));
        $writer->upsert($this->user, $this->data('anon-to-user'));
        $writer->upsert($this->user, $this->data('stays'));
        $ids        = array_map(fn (string $id): string => $this->reload($id)->getId(), ['to-user', 'to-person', 'stays']);
        $anonymised = [$this->reload('anon-to-user')->getId()];
        $writer->moveBetweenUsers(self::SOURCE, $this->user, $other, null, ['to-user', 'anon-to-user']);
        $writer->moveToPerson(self::SOURCE, $this->user, new ImportedTimeEntryPerson('p-2', 'Luis'), ['to-person']);
        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);

        $people->anonymiseDeleted($anonymised);
        self::assertSame(1, $people->settleDeleted($ids, FormerPersonTimeEntries::personRefOf($this->user), 'Ana'), 'only the row still with the deleted user');
        $this->entityManager()->clear();

        $owner = $this->reload('to-user')->getUser();
        self::assertInstanceOf(AppUser::class, $owner);
        self::assertSame($other->getId(), $owner->getId());
        self::assertSame('p-2', $this->reload('to-person')->getPersonRef());
        self::assertSame('Luis', $this->reload('to-person')->getPersonName());
        self::assertFalse($this->reload('anon-to-user')->isAnonymised());
        self::assertNotNull($this->reload('anon-to-user')->getUser());
        self::assertNull($this->reload('stays')->getUser());
        self::assertSame('Ana', $this->reload('stays')->getPersonName());
    }

    public function testSkippedRowsLeaveAWarningWithoutIds(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('owned'));
        $logger = new RecordingLogger();
        $quiet  = new ImportedTimeEntryWriter($this->store(), logger: $logger);

        $quiet->upsertMany([[new ImportedTimeEntryPerson('p-1'), $this->data('owned')]], skipMismatches: true);

        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('owned', (string) json_encode($logger->records));
        self::assertStringContainsString('"mismatched":1', (string) json_encode($logger->records));
    }

    public function testAMismatchStaysABatchFailureByDefaultAndIsAPerRowOutcomeOnRequest(): void
    {
        $writer = $this->writer();
        $writer->upsert($this->user, $this->data('owned'));
        $person = new ImportedTimeEntryPerson('p-1', 'Ana');
        $rows   = [[$person, $this->data('owned', null, 60)], [$person, $this->data('new')]];

        try {
            $writer->upsertMany($rows);
            self::fail('a mismatch refuses the batch by default');
        } catch (ImportedEntryOwnerMismatchException $exception) {
            self::assertSame('owned', $exception->externalId);
        }
        self::assertNull($this->store()->findByExternal(self::SOURCE, 'new'));

        $batch = $writer->upsertMany($rows, skipMismatches: true);
        self::assertSame([1, 1], [$batch->mismatched, $batch->created]);
        self::assertSame(ImportedTimeEntryOutcome::Mismatch, $batch->results[0]->outcome);
        self::assertSame(ImportedTimeEntryOutcome::Created, $batch->results[1]->outcome);
        $this->entityManager()->clear();
        self::assertSame(3600, $this->reload('owned')->getDurationSeconds(), 'nothing written for that row');
        self::assertNotNull($this->reload('owned')->getUser());
    }

    public function testAHostPostFlushThatFlushesCannotCancelTheSettling(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $this->entityManager()->clear();
        $this->enforceForeignKeys();
        $this->host()->flushOnPostFlush = true;
        $userId                         = (string) $this->user->getId();

        $this->entityManager()->remove($this->managedUser());
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        self::assertSame('user:' . $userId, $this->reload('e-1')->getPersonRef());
    }

    public function testARemoveInsideAnOnFlushWithNothingElseToFlushSettlesNothing(): void
    {
        $this->writer()->upsert($this->user, $this->data('e-1'));
        $this->entityManager()->clear();
        $this->enforceForeignKeys();
        $user                        = $this->managedUser();
        $loaded                      = $this->reload('e-1');
        $this->host()->removeOnFlush = $user;

        $this->entityManager()->flush();

        self::assertNotNull($this->entityManager()->find(AppUser::class, $user->getId()), 'Doctrine deleted nothing');
        self::assertSame($user, $loaded->getUser(), 'a loaded entry is read again');
        self::assertNull($loaded->getPersonRef());
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $entry = $this->reload('e-1');
        self::assertNotNull($entry->getUser());
        self::assertNull($entry->getPersonRef());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = [];
    }

    /**
     * @return array{int, int}
     */
    private function counts(ImportedTimeEntryMoveResult $result): array
    {
        return [$result->moved, $result->refusedScope];
    }

    private function assertMismatchOf(string $externalId, callable $call): void
    {
        try {
            $call();
            self::fail('expected a mismatch');
        } catch (ImportedEntryOwnerMismatchException $exception) {
            self::assertSame($externalId, $exception->externalId);
        }
    }

    private function lastEvent(): TimeEntriesMovedEvent
    {
        $event = end($this->events);
        self::assertInstanceOf(TimeEntriesMovedEvent::class, $event);

        return $event;
    }

    private function enforceForeignKeys(): void
    {
        $conn = $this->entityManager()->getConnection();
        if ($conn->getDatabasePlatform() instanceof SQLitePlatform) {
            $conn->executeStatement('PRAGMA foreign_keys = ON');
        }
    }

    private function host(): HostFlushListener
    {
        $host = self::getContainer()->get(HostFlushListener::class);
        self::assertInstanceOf(HostFlushListener::class, $host);

        return $host;
    }

    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function reload(string $externalId, string $source = self::SOURCE): TimeEntry
    {
        $entry = $this->store()->findByExternal($source, $externalId);
        self::assertInstanceOf(TimeEntry::class, $entry);

        return $entry;
    }

    private function store(): DoctrineOrmTimeEntryRepository
    {
        $store = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $store);

        return $store;
    }

    private function writer(): ImportedTimeEntryWriter
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRIES_MOVED, function (TimeEntriesMovedEvent $event): void {
            $this->events[] = $event;
        });

        return new ImportedTimeEntryWriter($this->store(), $dispatcher);
    }

    private function data(string $id, ?string $description = 'Imported', int $seconds = 3600, string $source = self::SOURCE): ImportedTimeEntryData
    {
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        return new ImportedTimeEntryData($source, $id, 'task-1', 'Implement login API', 'board-1', $start, $start->modify('+1 hour'), $seconds, true, $description);
    }
}
