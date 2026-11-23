<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\ProbeEntity\EntryNote;
use ReflectionProperty;
use Throwable;

/**
 * 2.0 (REQ-ARCH-001 rule 6, audit lessons XS / W1-2): TimeTrack writes only through its private entity manager, and
 * the application's entities and TimeTrack's rows never cross the two managers by accident. With a relation-mapped host
 * user (`AppUser`, many-to-one from every TimeTrack row) and a host entity that points at a TimeTrack row (`EntryNote`):
 *
 * - a host user the application holds (initialised, or an uninitialised reference) is written as the private manager's
 *   own reference to the same row, and the application keeps its instance;
 * - a host user persisted but not flushed fails clearly before anything is written, and the application's scheduled
 *   insert stays;
 * - a TimeTrack row the application's manager loaded and hands to a TimeTrack store is refused (never inserted twice);
 * - the application's own entity that references a TimeTrack row by id is written with its foreign key;
 * - a TimeTrack write never flushes, clears or detaches what the application's manager holds.
 *
 * Runs on SQLite and, in CI, on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (TIME_TRACK_TEST_DATABASE_URL).
 */
final class HostEntitiesAcrossManagersTest extends FunctionalTestCase
{
    public function testAHostUserTheApplicationHoldsIsWrittenAsAReference(): void
    {
        $host  = $this->entityManager();
        $timer = $this->timers()->start($this->user, 'task-1', ClientType::Web);
        $entry = $this->timers()->stop($this->user);

        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertTrue($host->contains($this->user), 'the application keeps its user');
        self::assertNotSame($this->user, $entry->getUser(), 'the private manager writes its own reference to the same row');
        self::assertSame((string) $this->user->getId(), $entry->getUserId());
        self::assertFalse($host->contains($timer));
        self::assertSame((string) $this->user->getId(), (string) $host->getConnection()->fetchOne('SELECT user_id FROM time_track_entries WHERE id = ?', [$entry->getId()]));
    }

    public function testAnUninitialisedHostReferenceIsWrittenWithoutLoadingIt(): void
    {
        $host = $this->entityManager();
        $id   = $this->user->getId();
        $this->clearEntityManagers();
        $proxy = $host->getReference(AppUser::class, $id);
        self::assertInstanceOf(AppUser::class, $proxy);
        self::assertTrue($host->getUnitOfWork()->isUninitializedObject($proxy));

        $timer = $this->timers()->start($proxy, 'task-1', ClientType::Web);

        self::assertSame((string) $id, $timer->getUserId());
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers WHERE user_id = ?', [$id]));
        self::assertTrue($host->contains($proxy), 'still the application\'s object');
    }

    public function testAHostUserPersistedButNotFlushedFailsClearlyAndNothingIsWritten(): void
    {
        $host    = $this->entityManager();
        $unsaved = new AppUser('unsaved@example.com', 'secret');
        $host->persist($unsaved);

        $failure = $this->failureOf(fn (): ActiveTimer => $this->timers()->start($unsaved, 'task-1', ClientType::Web));

        self::assertInstanceOf(LogicException::class, $failure);
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'), 'nothing written');
        self::assertSame(0, (int) $host->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'unsaved@example.com'"), 'the user is not written by TimeTrack either');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($unsaved), 'the application\'s scheduled insert stays');
    }

    public function testATimeTrackRowPointingAtAnUnsavedHostUserIsRefusedBeforeAnythingIsWritten(): void
    {
        $host    = $this->entityManager();
        $private = $this->bundleEntityManager();
        $unsaved = new AppUser('unsaved-row@example.com', 'secret');
        $host->persist($unsaved);
        $start = new DateTimeImmutable('2026-10-05 09:00:00');
        $private->persist(new TimeEntry($unsaved, 'task-1', 'T', null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web));

        $failure = $this->failureOf(static fn () => $private->flush());

        self::assertInstanceOf(LogicException::class, $failure);
        self::assertStringContainsString('has no row yet (persisted but not flushed, or not saved at all). Flush your', $failure->getMessage());
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'nothing written');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($unsaved), 'the application\'s scheduled insert stays');
    }

    public function testATimeTrackRowLoadedByTheApplicationIsNeverInsertedTwice(): void
    {
        $host  = $this->entityManager();
        $start = new DateTimeImmutable('2026-10-05 09:00:00');
        $this->entries()->save(new TimeEntry($this->user, 'task-1', 'T', null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web));
        $id = (string) $host->getConnection()->fetchOne('SELECT id FROM time_track_entries');
        $this->clearEntityManagers();
        $loaded = $host->find(TimeEntry::class, $id);
        self::assertInstanceOf(TimeEntry::class, $loaded);
        $loaded->markBillable(true);

        $failure = $this->failureOf(fn () => $this->entries()->save($loaded));

        self::assertInstanceOf(LogicException::class, $failure);
        self::assertStringContainsString('was loaded by another entity manager', $failure->getMessage());
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'no duplicate row');
        self::assertTrue($host->contains($loaded), 'never detached from the application\'s manager');

        // The same change through an instance TimeTrack loaded is written.
        $own = $this->bundleEntityManager()->find(TimeEntry::class, $id);
        self::assertInstanceOf(TimeEntry::class, $own);
        $own->markBillable(true);
        $this->entries()->save($own);
        self::assertTrue((bool) $host->getConnection()->fetchOne('SELECT billable FROM time_track_entries WHERE id = ?', [$id]));
    }

    public function testAHostEntityReferencingATimeTrackRowByIdIsWritten(): void
    {
        $host  = $this->entityManager();
        $timer = $this->timers()->start($this->user, 'task-1', ClientType::Web);
        $entry = $this->timers()->stop($this->user);
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertFalse($host->contains($timer));

        $reference = $host->getReference(TimeEntry::class, $entry->getId());
        self::assertInstanceOf(TimeEntry::class, $reference);
        $note = new EntryNote($reference, 'reviewed', $this->user);
        $host->persist($note);
        $host->flush();

        self::assertSame($entry->getId(), (string) $host->getConnection()->fetchOne('SELECT entry_id FROM app_entry_note WHERE id = ?', [$note->id]));
    }

    public function testATimeTrackWriteNeverFlushesClearsOrDetachesTheApplicationsManager(): void
    {
        $host  = $this->entityManager();
        $other = $this->createUser('other@example.com');
        (new ReflectionProperty(AppUser::class, 'email'))->setValue($this->user, 'pending-change@example.com');

        $this->timers()->start($this->user, 'task-1', ClientType::Web);
        $this->timers()->stop($this->user);

        self::assertSame('person@example.com', $host->getConnection()->fetchOne('SELECT email FROM app_user WHERE id = ?', [$this->user->getId()]), 'the pending change is not written');
        self::assertTrue($host->contains($this->user));
        self::assertTrue($host->contains($other));
        $host->getUnitOfWork()->computeChangeSets();
        self::assertSame(['person@example.com', 'pending-change@example.com'], $host->getUnitOfWork()->getEntityChangeSet($this->user)['email'] ?? null, 'still pending, with its change');
    }

    /**
     * What the action threw (the test fails when it returns).
     */
    private function failureOf(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('Expected a failure.');
    }

    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    private function entries(): TimeEntryRepositoryInterface
    {
        $entries = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(TimeEntryRepositoryInterface::class, $entries);

        return $entries;
    }
}
