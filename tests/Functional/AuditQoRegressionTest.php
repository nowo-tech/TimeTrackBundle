<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryResult;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\RacingCachePool;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Throwable;

use const DATE_ATOM;

/**
 * 1.27.0, audit QO (shared entity manager, the default mode): the auditor's proofs of concept, inverted. A failed unit
 * never leaves application rows managed but rolled back (QO-M1); a committed `Idempotency-Key` answer is replayed even
 * after its entry was deleted (QO-L2). Run on SQLite and, in CI, on MySQL and PostgreSQL.
 */
#[Group('shared-entity-manager')]
final class AuditQoRegressionTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['manual_entries' => ['enabled' => true], 'client_api' => ['rate_limit' => ['cache_pool' => RacingCachePool::class]]];
    }

    /**
     * QO-M1: `saveAll()` called directly with application work pending; a failure after the unit's flush (a host
     * `postFlush` listener here; a failed COMMIT in production) never leaves the application's row managed but lost:
     * it is written, or the manager is closed (loud).
     */
    public function testAFailedSaveAllNeverSilentlyLosesTheApplicationsPendingRow(): void
    {
        $em      = $this->entityManager();
        $pending = new AppUser('qo-pending@example.com', 'secret');
        $em->persist($pending);
        $listener = $this->throwingPostFlush(1);
        $em->getEventManager()->addEventListener([Events::postFlush], $listener);
        $repository = self::getContainer()->get(DoctrineOrmTimeEntryRepository::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);

        $failure = $this->failureOf(fn () => $repository->saveAll([$this->entry('task-qo')]));
        $em->getEventManager()->removeEventListener([Events::postFlush], $listener);

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertFalse($em->isOpen(), 'the rollback undid the application\'s row: the manager is closed (loud), never silently stale');
        (new TimeTrackTransactionRunner($em, $this->registry()))->reset();
        self::assertTrue($this->entityManager()->isOpen(), 'replaced at the boundary');
    }

    /**
     * QO-M1: an import flushes the application's pending work first, on its own: a failure of the import's unit never
     * takes it along.
     */
    public function testAnImportFlushesTheApplicationsPendingWorkFirst(): void
    {
        $em      = $this->entityManager();
        $pending = new AppUser('qo-import@example.com', 'secret');
        $em->persist($pending);
        $listener = $this->throwingPostFlush(2);
        $em->getEventManager()->addEventListener([Events::postFlush], $listener);
        $repository = self::getContainer()->get(DoctrineOrmTimeEntryRepository::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $start = new DateTimeImmutable('2026-10-05 09:00:00');

        $failure = $this->failureOf(fn (): ImportedTimeEntryResult => (new ImportedTimeEntryWriter($repository))->upsert($this->user, new ImportedTimeEntryData('clickup:qo', 'x-1', 'task-1', 'T', null, $start, $start->modify('+1 hour'), 3600)));
        $em->getEventManager()->removeEventListener([Events::postFlush], $listener);

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertTrue($em->isOpen(), 'the failed unit wrote only TimeTrack rows');
        self::assertSame(1, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'qo-import@example.com'"), 'written before the import\'s unit');
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'));
    }

    /**
     * QO-M1 (`run()`): an application row persisted during the unit (what a host port does) and flushed inside it is
     * never left managed but rolled back: the manager is closed.
     */
    public function testAFailedRunClosesTheManagerWhenItsFlushWroteApplicationRows(): void
    {
        $em       = $this->entityManager();
        $listener = $this->throwingPostFlush(2);
        $em->getEventManager()->addEventListener([Events::postFlush], $listener);
        $runner = self::getContainer()->get(TimeTrackTransactionRunner::class);
        self::assertInstanceOf(TimeTrackTransactionRunner::class, $runner);
        $host = new AppUser('qo-during@example.com', 'secret');

        $failure = $this->failureOf(fn () => $runner->run(function () use ($em, $host): void {
            $em->persist($this->entry('task-qo-2'));
            $em->persist($host);
        }));
        $em->getEventManager()->removeEventListener([Events::postFlush], $listener);

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertFalse($em->isOpen(), 'closed: the application\'s next flush fails loudly');
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM app_user WHERE email = 'qo-during@example.com'"));
        $runner->reset();
        self::assertTrue($this->entityManager()->isOpen());
    }

    /**
     * QO-L2: a retry with the same key and body after the entry was deleted replays the committed answer (as in 1.26)
     * and writes nothing.
     */
    public function testARetryAfterTheEntryWasDeletedReplaysTheCommittedAnswer(): void
    {
        $pool = self::getContainer()->get(RacingCachePool::class);
        self::assertInstanceOf(RacingCachePool::class, $pool);
        $pool->clear();
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $token   = (string) $auth->issueToken($this->user, ClientType::Desktop, null, [ClientScope::EntriesWrite])['token'];
        $start   = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $payload = ['taskId' => 'task-1', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];

        $first = $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'qo-1']);
        self::assertResponseStatusCodeSame(201);
        $firstId = $first['entry']['id'] ?? null;
        $this->entityManager()->getConnection()->delete('time_track_entries', ['id' => $firstId]);
        $this->entityManager()->clear();

        $again = $this->api('POST', '/api/time-track/entries', $payload, $token, ['HTTP_IDEMPOTENCY_KEY' => 'qo-1']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($firstId, $again['entry']['id'] ?? null, 'the stored answer');
        self::assertSame(0, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'nothing written again');
    }

    private function throwingPostFlush(int $on): object
    {
        return new class($on) {
            private int $flushes = 0;

            public function __construct(private readonly int $throwOn)
            {
            }

            public function postFlush(): void
            {
                if (++$this->flushes === $this->throwOn) {
                    throw new RuntimeException('host postFlush listener failed once');
                }
            }
        };
    }

    private function failureOf(callable $action): ?Throwable
    {
        try {
            $action();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function registry(): ManagerRegistry
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function entry(string $taskId): TimeEntry
    {
        $start = new DateTimeImmutable('2026-10-05 09:00:00');

        return new TimeEntry($this->user, $taskId, 'T', 'board-1', $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web);
    }
}
