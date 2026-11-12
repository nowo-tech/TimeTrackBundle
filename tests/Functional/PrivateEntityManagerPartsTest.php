<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Doctrine\PrivateEntityManagerConnection;
use Nowo\TimeTrackBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\LedgerScopeFilter;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function assert;
use function bin2hex;
use function json_encode;
use function random_bytes;

/**
 * 1.23.0 private entity manager parts (`database.private_entity_manager`, REQ-ARCH-001 rule 6; adapted from AuthKitBundle
 * 1.46.0 and VaultBundle 1.19.0, audits NJ-2, NJV-1, NF-2, NF2-2, NF3-2, NF-7): the rollback middleware, the replacement
 * rules, the boundary drop that cannot be poisoned, the registry the TimeTrack stores see, the savepoint helper, the
 * references to rows of another unit of work and the build pass.
 */
final class PrivateEntityManagerPartsTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['database' => ['private_entity_manager' => true]];
    }

    public function testOnlyARollbackOfRowsThePrivateManagerWroteMakesItStale(): void
    {
        $private = $this->privateManager();
        $real    = $private->getConnection();
        $inner   = $this->createMock(DriverConnection::class);
        $inner->method('exec')->willReturn(0);
        $inner->method('commit')->willReturnOnConsecutiveCalls(null, self::throwException(new RuntimeException('commit failed')));
        $connection = new PrivateEntityManagerConnection($inner, $private);
        $real->beginTransaction();

        $this->write($private);
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $connection->exec('ROLLBACK TO SAVEPOINT nowo_time_track_0a1b2c');
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertFalse($private->isStale(), 'NJ-2: a TimeTrack savepoint or a deeper level the manager did not write in');
        $connection->exec('ROLLBACK TO SAVEPOINT host_point');
        self::assertTrue($private->isStale(), 'a savepoint of unknown level: conservative');

        $private->reset();
        $this->write($private);
        $connection->rollBack();
        self::assertTrue($private->isStale());

        $private->reset();
        $this->write($private);
        $connection->commit();
        $connection->rollBack();
        self::assertFalse($private->isStale(), 'committed rows are not undone by a later rollback');

        $private->reset();
        $this->write($private);
        try {
            $connection->commit();
            self::fail('the commit failure is rethrown');
        } catch (RuntimeException) {
        }
        self::assertTrue($private->isStale());

        // Rows written in a nested level move down when its savepoint is released.
        $private->reset();
        $real->beginTransaction();
        $this->write($private);
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertFalse($private->isStale());
        $connection->rollBack();
        self::assertTrue($private->isStale());
        $real->rollBack();
        $real->rollBack();

        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($this->createMock(DriverConnection::class));
        self::assertInstanceOf(PrivateEntityManagerConnection::class, (new PrivateEntityManagerMiddleware($private))->wrap($driver)->connect([]));
    }

    public function testAStaleManagerWithPendingWorkIsKeptUntilFlushed(): void
    {
        $private = $this->privateManager();
        $real    = $private->getConnection();
        $real->beginTransaction();
        $this->write($private);
        $live = $private->live();
        $private->persist($this->line());
        $private->connectionRolledBack(1);

        self::assertSame($live, $private->live(), 'pending inserts survive a rollback signal');
        $private->flush();
        $private->connectionRolledBack(1);
        self::assertNotSame($live, $private->live(), 'no pending work: replaced');
        $private->connectionRolledBack(null);
        self::assertFalse($private->isStale(), 'a fresh manager wrote nothing');
        $private->wrapInTransaction(static fn (): bool => true);
        $real->rollBack();
        $private->clear();
        (new TimeTrackEntityManager($this->doctrine()))->clear();
    }

    public function testAClosedManagerIsReplacedAndLoadsAndRefreshesAreTracked(): void
    {
        $private = self::getContainer()->get(TimeTrackEntityManager::class);
        self::assertInstanceOf(TimeTrackEntityManager::class, $private);
        $live = $private->live();
        $live->close();
        self::assertNotSame($live, $private->live(), 'a closed manager is created again');

        $line = $this->line();
        $private->persist($line);
        $private->flush();
        $private->clear();
        $real = $private->getConnection();
        $real->beginTransaction();
        $loaded = $private->find(BalanceLedgerLine::class, $line->getId());
        self::assertInstanceOf(BalanceLedgerLine::class, $loaded);
        $private->refresh($loaded);
        $private->getConnection()->rollBack();
        self::assertTrue($private->isStale(), 'NJV-1: a load inside a rolled-back transaction makes the identity map stale');
        $private->postLoad(new PostLoadEventArgs($loaded, $this->entityManager()));
    }

    public function testABoundaryThatCannotInspectOrClearStillDropsTheManager(): void
    {
        $logger  = new RecordingLogger();
        $private = new TimeTrackEntityManager($this->doctrine(), 'default', $logger);
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('isOpen')->willReturn(true);
        $failing->method('getUnitOfWork')->willThrowException(new RuntimeException('change sets'));
        $failing->method('clear')->willThrowException(new RuntimeException('onClear listener'));
        (new ReflectionProperty(TimeTrackEntityManager::class, 'current'))->setValue($private, $failing);

        $private->reset();

        self::assertFalse($private->isCreated());
        self::assertCount(2, $logger->records, 'inspection and clear failures are logged, never rethrown');

        $quiet = new TimeTrackEntityManager($this->doctrine(), 'default', new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        });
        (new ReflectionProperty(TimeTrackEntityManager::class, 'current'))->setValue($quiet, $failing);
        $quiet->reset();
        self::assertFalse($quiet->isCreated());

        $closed = new TimeTrackEntityManager($this->doctrine());
        $closed->live()->close();
        $closed->reset();
        self::assertFalse($closed->isCreated());
    }

    public function testABoundaryLogsTheDroppedChangesByClassOnly(): void
    {
        $logger  = new RecordingLogger();
        $private = new TimeTrackEntityManager($this->doctrine(), 'default', $logger);
        $private->persist($this->line('secret-owner'));
        $private->reset();

        self::assertCount(1, $logger->records);
        self::assertSame([BalanceLedgerLine::class => 1], $logger->records[0]['context']['changes'] ?? null);
        self::assertStringNotContainsString('secret-owner', (string) json_encode($logger->records));

        $silent = new TimeTrackEntityManager($this->doctrine());
        $silent->persist($this->line());
        $silent->reset();
        self::assertFalse($silent->isCreated(), 'without a logger the changes are dropped silently');
        $nothing = new TimeTrackEntityManager($this->doctrine(), 'default', $logger);
        $nothing->live();
        $nothing->reset();
        self::assertCount(1, $logger->records, 'nothing pending: nothing logged');
    }

    public function testTheSourceMustBeAnOrmManagerThatMapsTheTimeTrackEntities(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not map');
        (new TimeTrackEntityManager($doctrine, 'other'))->source();
    }

    public function testTheSourceSqlFiltersAreMirroredWithTheirParameters(): void
    {
        $doctrine = $this->doctrine();
        $source   = $doctrine->getManager();
        assert($source instanceof EntityManagerInterface);
        $private = new TimeTrackEntityManager($doctrine);
        $private->persist($this->line(null, 'org-a'));
        $private->persist($this->line(null, 'org-b'));
        $private->flush();
        $source->getConfiguration()->addFilter('test_ledger_scope', LedgerScopeFilter::class);

        $source->getFilters()->enable('test_ledger_scope')->setParameter('scope', 'org-a');
        self::assertSame(['org-a'], $this->scopes($private));
        $copy = $private->live()->getFilters()->getFilter('test_ledger_scope');
        self::assertSame($copy, $private->live()->getFilters()->getFilter('test_ledger_scope'), 'an unchanged filter is kept');
        $source->getFilters()->getFilter('test_ledger_scope')->setParameter('scope', 'org-b');
        self::assertSame(['org-b'], $this->scopes($private), 'a changed parameter is copied');
        $source->getFilters()->disable('test_ledger_scope');
        $source->getFilters()->enable('test_ledger_scope')->setParameterList('scopes', ['org-a', 'org-b'], 'string');
        self::assertSame(['org-a', 'org-b'], $this->scopes($private), 'a list parameter keeps its type');
        $source->getFilters()->disable('test_ledger_scope');
        self::assertSame(['org-a', 'org-b'], $this->scopes($private), 'a filter the source disabled is disabled');
    }

    public function testRepositoriesComeFromTheBundleFactoryPerManager(): void
    {
        $private    = $this->privateManager();
        $repository = $private->getRepository(BalanceLedgerLine::class);
        self::assertSame($repository, $private->getRepository(BalanceLedgerLine::class));
        $private->reset();
        self::assertNotSame($repository, $private->getRepository(BalanceLedgerLine::class), 'a new manager, a new repository');
        self::assertSame($private->live(), $private->getRepository(BalanceLedgerLine::class)->createQueryBuilder('l')->getEntityManager());
    }

    public function testUndefinedMagicPropertiesFail(): void
    {
        $this->expectException(LogicException::class);
        $this->privateManager()->__get('other');
    }

    public function testTheRegistryServesThePrivateManagerAndDelegatesTheRest(): void
    {
        $doctrine = $this->doctrine();
        $private  = new TimeTrackEntityManager($doctrine);
        $registry = new TimeTrackManagerRegistry($doctrine, $private, 'default');

        self::assertSame($private, $registry->getManager());
        self::assertSame($private, $registry->getManager('default'));
        self::assertSame($doctrine->getManagers(), $registry->getManagers());
        self::assertSame($doctrine->getManagerNames(), $registry->getManagerNames());
        self::assertSame($doctrine->getDefaultManagerName(), $registry->getDefaultManagerName());
        self::assertSame($doctrine->getDefaultConnectionName(), $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame($doctrine->getConnections(), $registry->getConnections());
        self::assertSame($doctrine->getConnectionNames(), $registry->getConnectionNames());
        self::assertSame($doctrine->getManagerForClass(AppUser::class), $registry->getManagerForClass(AppUser::class));
        // A Doctrine proxy class name (no such class is loaded: the registry only reads the name).
        self::assertSame($private, $registry->getManagerForClass('Proxies\\__CG__\\' . TimeEntry::class)); // @phpstan-ignore argument.type
        self::assertSame($private->getRepository(BalanceLedgerLine::class), $registry->getRepository(BalanceLedgerLine::class));
        self::assertSame($doctrine->getRepository(AppUser::class), $registry->getRepository(AppUser::class));
        $private->live();
        self::assertSame($private, $registry->resetManager());
        self::assertFalse($private->isCreated(), 'resetting the source name drops the private manager');

        $other = new TimeTrackManagerRegistry($doctrine, $private, 'time_track');
        self::assertSame($doctrine->getManager(), $other->getManager(), 'another name: the application manager');
        self::assertSame($doctrine->getManager('default'), $other->resetManager('default'));
    }

    public function testATimeTrackWriteInsideATransactionRunsInASavepointWithThePrivateManagerOnly(): void
    {
        $private    = $this->privateManager();
        $host       = $private->source();
        $connection = $private->getConnection();
        self::assertSame(1, TimeTrackSavepoint::write($connection, static fn (): int => 1, $private), 'outside a transaction: as is');

        $connection->beginTransaction();
        try {
            TimeTrackSavepoint::write($connection, static function (): never {
                throw new RuntimeException('failing statement');
            }, $private);
        } catch (RuntimeException) {
        }
        self::assertTrue($connection->isTransactionActive(), 'the host transaction is untouched');
        self::assertSame(2, TimeTrackSavepoint::write($connection, static fn (): int => 2, $private));
        self::assertSame(1, $connection->getTransactionNestingLevel());

        // The original failure wins when rolling back to the savepoint fails too (e.g. a dropped connection).
        $broken = $this->createMock(Connection::class);
        $broken->method('isTransactionActive')->willReturn(true);
        $broken->method('getDatabasePlatform')->willReturn($connection->getDatabasePlatform());
        $broken->method('rollbackSavepoint')->willThrowException(new RuntimeException('rollback failed'));
        $rethrown = null;
        try {
            TimeTrackSavepoint::write($broken, static function (): never {
                throw new RuntimeException('original failure');
            }, $private);
        } catch (RuntimeException $exception) {
            $rethrown = $exception->getMessage();
        }
        self::assertSame('original failure', $rethrown);

        // The application's manager (default mode): exactly the statement, no savepoint.
        $levels = [];
        TimeTrackSavepoint::write($connection, static function () use ($connection, &$levels): int {
            $levels[] = $connection->getTransactionNestingLevel();

            return 3;
        }, $host);
        self::assertSame([1], $levels);
        $connection->rollBack();
    }

    public function testAnEntryPointingAtAnObjectOfAnotherUnitOfWorkIsWrittenWithAReference(): void
    {
        // Your user as your manager loaded it, and a TimeTrack row a previous private manager loaded.
        $private = $this->privateManager();
        $user    = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);
        $entry = new TimeEntry($user, 'task-1', 'Task', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Web);
        $private->persist($entry);
        $private->flush();

        self::assertNotSame($user, $entry->getUser(), 'the private manager\'s reference to the same row');
        self::assertSame((string) $user->getId(), $entry->getUserId());
        self::assertTrue($this->entityManager()->contains($user), 'your object stays yours');
        self::assertFalse($private->live()->contains($user));
        $this->entityManager()->clear();
        self::assertSame((string) $user->getId(), $this->entityManager()->find(TimeEntry::class, $entry->getId())?->getUserId());
    }

    public function testTheBuildPassWrapsOnlyTheConnectionOfTheSourceManager(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(TimeTrackExtension::PRIVATE_ENTITY_MANAGER_SOURCE_PARAMETER, 'main');
        $container->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition(EntityManagerInterface::class, [new Reference('doctrine.dbal.time_connection')]));
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'time']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $container->removeDefinition('doctrine.orm.main_entity_manager');
        $container->setParameter('doctrine.default_connection', 'primary');
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'primary']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $bare = new ContainerBuilder();
        $bare->setParameter(TimeTrackExtension::PRIVATE_ENTITY_MANAGER_SOURCE_PARAMETER, ['not', 'a', 'name']);
        $bare->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        (new PrivateEntityManagerPass())->process($bare);
        self::assertSame([['connection' => 'default']], $bare->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $off = new ContainerBuilder();
        $off->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        (new PrivateEntityManagerPass())->process($off);
        self::assertSame([[]], $off->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'), 'option off: nothing wired');
        (new PrivateEntityManagerPass())->process(new ContainerBuilder());
        self::assertTrue(PrivateEntityManagerPass::supportsRollbackMiddleware());
    }

    private function write(TimeTrackEntityManager $private): void
    {
        $private->persist($this->line());
        $private->flush();
    }

    private function line(?string $ownerRef = null, string $scopeKey = 'org-a'): BalanceLedgerLine
    {
        return new BalanceLedgerLine(null, $ownerRef ?? bin2hex(random_bytes(4)), $scopeKey, '2026-11-02', LedgerKind::Adjustment, 60, new DateTimeImmutable(), null, bin2hex(random_bytes(4)));
    }

    /**
     * @return list<string>
     */
    private function scopes(TimeTrackEntityManager $private): array
    {
        $private->clear();
        $scopes = [];
        /** @var list<BalanceLedgerLine> $lines */
        $lines = $private->createQueryBuilder()->select('l')->from(BalanceLedgerLine::class, 'l')->orderBy('l.scopeKey')->getQuery()->getResult();
        foreach ($lines as $line) {
            $scopes[] = $line->getScopeKey();
        }

        return $scopes;
    }

    private function doctrine(): ManagerRegistry
    {
        $doctrine = self::getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);

        return $doctrine;
    }

    private function privateManager(): TimeTrackEntityManager
    {
        $private = new TimeTrackEntityManager($this->doctrine());
        $private->live();

        return $private;
    }
}
