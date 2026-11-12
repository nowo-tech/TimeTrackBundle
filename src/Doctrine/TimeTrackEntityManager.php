<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Closure;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Query\Filter\Parameter;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_keys;
use function array_sum;
use function is_object;
use function is_string;
use function ksort;
use function sprintf;

/**
 * TimeTrack's private entity manager (1.23.0, REQ-ARCH-001 rule 6; opt-in with
 * `nowo_time_track.database.private_entity_manager: true` until 2.0).
 *
 * Up to 1.22 the bundle wrote its rows (time entries, timers, timesheets, audit rows, ledger lines, client tokens, device
 * codes, calendar rows) through `database.entity_manager` (default: the application's `default` manager): saving one
 * flushed every change the application had left pending in that unit of work, and a failed write closed it. With the
 * option on, the bundle writes and reads its own entities through a **private** manager created on the connection,
 * configuration (mapping, metadata cache, proxies; its own repository factory, {@see TimeTrackRepositoryFactory}) and
 * event manager (table prefix, host listeners) of that source manager. Its unit of work holds only TimeTrack rows (and
 * references to the host users they point at): a TimeTrack flush never commits an application change, and a failed
 * write closes only this manager. The tables do not move. The connection is shared, so a transaction the application
 * opened includes the private manager's rows (its flush then runs in a savepoint).
 *
 * The object is a stable decorator (the repositories keep it); the wrapped manager is resolved on every call and
 * created again when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) undid rows it wrote inside a transaction, so its identity map may describe
 *   rows that no longer exist ({@see PrivateEntityManagerMiddleware}; DBAL 4 only, with DBAL 3 the manager is only
 *   replaced at the next boundary). A rollback of a level it did not write in leaves it alone. A stale manager that
 *   still has scheduled inserts, updates or deletes is **kept** (replacing it would silently drop them) until that
 *   work is flushed or the next unit-of-work boundary;
 * - {@see reset()} ran: at every outermost unit-of-work boundary ({@see PrivateEntityManagerSubscriber}: start and end
 *   of the main request, of a Messenger message and of the outermost console command) and at `kernel.reset`. Changes
 *   still pending at that moment are dropped and logged as a warning (entity classes and counts, never a value).
 *
 * The SQL filters enabled on the source manager, with their parameters, are mirrored on the private manager on every
 * call, and a filter whose parameters changed is re-created, so a parameter the source no longer has never survives.
 *
 * Adapted from AuthKitBundle 1.46.0 (VaultBundle 1.19.0; audits NJ-2, NJV-1, NF-2, NF2-2, NF3-2, NF-7, NZ-M1).
 *
 * @internal
 */
final class TimeTrackEntityManager extends EntityManagerDecorator implements ResetInterface
{
    private ?EntityManagerInterface $current = null;

    /** A rollback undid rows the private manager wrote since it was created or last replaced. */
    private bool $stale = false;

    /**
     * Transaction nesting levels of the connection in which the private manager wrote (flush, bulk DQL / DBAL, refresh)
     * or loaded rows that nobody committed yet (audits NJ-2, NJV-1: only a rollback of one of these levels makes the
     * identity map stale).
     *
     * @var array<int, true>
     */
    private array $uncommitted = [];

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry (the source manager is resolved per call)
     * @param string $sourceManagerName `database.entity_manager` (the manager 1.22 wrote the TimeTrack rows through)
     * @param LoggerInterface|null $logger warns when a boundary drops pending changes
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $sourceManagerName = 'default',
        private readonly ?LoggerInterface $logger = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->{'wrapped'});
    }

    /**
     * The wrapped manager of {@see EntityManagerDecorator} (the only magic property).
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed, with the source's SQL filters.
     */
    public function live(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->current;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && !$this->hasScheduledWork($current))) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                $current->clear();
            }
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();
            // @igor-ignore - Bundle-owned unit of work: dropped at every boundary, replaced when closed, stale or on another connection.
            $current = $this->current = new EntityManager($source->getConnection(), $this->configuration($source), $eventManager instanceof EventManager ? $eventManager : null);
            // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
            $this->stale = false;
            // @igor-ignore - Bundle-owned bookkeeping, reset when the manager is replaced.
            $this->uncommitted = [];
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * Whether a private manager exists right now (no side effect; tests and the boundary subscriber).
     */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /**
     * Whether a rollback made the private manager's identity map stale (no side effect; tests).
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * A transaction (level 1) or savepoint (its level; null when the level is unknown) was rolled back on the
     * connection (told by {@see PrivateEntityManagerConnection}). The manager becomes stale only when the rollback
     * undid rows it wrote (audit NJ-2: an unrelated rollback of the application, or of a TimeTrack savepoint, leaves it
     * alone); a stale manager is replaced at its next use unless it has scheduled work. It is never cleared here (the
     * rollback may run inside the private manager's own failing flush).
     */
    public function connectionRolledBack(?int $level = 1): void
    {
        $undone = false;
        foreach (array_keys($this->uncommitted) as $written) {
            if ($level === null || $written >= $level) {
                $undone = true;
                // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
                unset($this->uncommitted[$written]);
            }
        }
        if ($undone && $this->current instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    /**
     * The outermost transaction was committed: every row the private manager wrote is durable.
     */
    public function connectionCommitted(): void
    {
        // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
        $this->uncommitted = [];
    }

    /**
     * A savepoint of this level was released: its rows now belong to the level below.
     */
    public function savepointReleased(int $level): void
    {
        if (isset($this->uncommitted[$level])) {
            // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
            unset($this->uncommitted[$level]);
            if ($level > 1) {
                // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
                $this->uncommitted[$level - 1] = true;
            }
        }
    }

    /**
     * The repository of a class, from the private manager's own repository factory ({@see TimeTrackRepositoryFactory}):
     * built and memoised per private manager (dropped with it), or the application's service repository for a
     * `ServiceEntityRepository` class (audit NZ-M1: such a repository cannot be built for another manager).
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return EntityRepository<T>
     */
    public function getRepository(string $className): EntityRepository
    {
        return $this->live()->getRepository($className);
    }

    /**
     * Flushes the private manager. Before that, every TimeTrack entity it holds that points (many-to-one / one-to-one)
     * at an object of another unit of work (your user, as your manager or your firewall loaded it) is pointed at this
     * manager's reference to the same row instead: the private manager never treats those objects as new, and never
     * writes them.
     */
    public function flush(): void
    {
        $live = $this->live();
        $this->attachForeignReferences($live);
        $live->flush();
        $this->wrote();
    }

    /**
     * Replaces, on the scheduled and managed TimeTrack entities, each to-one association value that this manager neither
     * manages nor has scheduled for insertion (an object of another unit of work: your user, or a TimeTrack row your
     * manager or a previous private manager loaded) by a reference of the private manager (same class and identifier).
     * The bundle never cascades persist, so such a value is always an existing row.
     */
    private function attachForeignReferences(EntityManagerInterface $live): void
    {
        $unitOfWork = $live->getUnitOfWork();
        $entities   = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                $entities[] = $entity;
            }
        }

        foreach ($entities as $entity) {
            if (!TimeTrackManagerRegistry::isBundleEntity($entity::class)) {
                continue;
            }
            $metadata = $live->getClassMetadata($entity::class);
            foreach ($metadata->getAssociationNames() as $field) {
                $value = $metadata->isSingleValuedAssociation($field) ? $metadata->getFieldValue($entity, $field) : null;
                if (!is_object($value) || $live->contains($value)) {
                    continue;
                }
                $target     = $live->getClassMetadata($value::class);
                $identifier = $target->getIdentifierValues($value);
                if ($identifier !== []) {
                    $metadata->setFieldValue($entity, $field, $live->getReference($target->getName(), $identifier));
                }
            }
        }
    }

    /**
     * Refreshes through the private manager and records the level the loaded row waits in.
     */
    public function refresh(object $object, LockMode|int|null $lockMode = LockMode::NONE): void
    {
        $this->live()->refresh($object, $lockMode);
        $this->wrote();
    }

    /**
     * An TimeTrack statement that bypassed the unit of work (bulk DQL, DBAL) wrote at the current level (audit NJV-1).
     */
    public function noteWrite(): void
    {
        $this->wrote();
    }

    /**
     * Rows loaded by the private manager inside a transaction may be the application's uncommitted writes: a rollback
     * of that level makes the identity map stale too (audit NJV-1). Doctrine `postLoad` listener; other managers'
     * loads are ignored.
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        if ($this->current instanceof EntityManagerInterface && $args->getObjectManager() === $this->current) {
            $this->wrote();
        }
    }

    /**
     * Runs the function in a transaction of the private manager (its flush included) and records the level.
     */
    public function wrapInTransaction(callable $func): mixed
    {
        $live = $this->live();
        try {
            // The manager flushes after the function: the rows of other units of work are referenced first.
            // @igor-ignore - Closure passed to this call only and dropped after it (no state kept).
            return $live->wrapInTransaction(function (EntityManagerInterface $manager) use ($func, $live): mixed {
                $result = $func($manager);
                $this->attachForeignReferences($live);

                return $result;
            });
        } finally {
            $this->wrote();
        }
    }

    /**
     * Records the nesting level the private manager's last write is waiting in (none outside a transaction).
     */
    private function wrote(): void
    {
        $level = $this->current?->getConnection()->getTransactionNestingLevel() ?? 0;
        if ($level > 0) {
            // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
            $this->uncommitted[$level] = true;
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager and never creates the private
     * one.
     */
    public function clear(): void
    {
        if ($this->current instanceof EntityManagerInterface && $this->current->isOpen()) {
            $this->current->clear();
        }
    }

    /**
     * Drops the private manager (a unit-of-work boundary or `kernel.reset`); the next call creates a fresh one.
     * Changes still pending are lost: they are logged as a warning with their entity classes and counts.
     */
    public function reset(): void
    {
        $current = $this->current;
        // Dropped first, so a failure below can never keep a manager across the boundary (audit NF2-2).
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->current = null;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->stale = false;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->uncommitted = [];
        if (!$current instanceof EntityManagerInterface || !$current->isOpen()) {
            return;
        }

        try {
            $this->warnAboutDroppedChanges($current);
        } catch (Throwable $exception) {
            // Computing the change sets can throw (invalid association, detached or half-built entity).
            $this->logGenericWarning($exception);
        }

        try {
            $current->clear();
        } catch (Throwable $exception) {
            // A host onClear listener that throws must not abort the boundary (audit NF3-2): the manager is dropped.
            $this->logGenericWarning($exception);
        }
    }

    /**
     * The application's manager whose connection, configuration, events and filters the private one shares. Never
     * flushed, cleared, closed or reset by the bundle.
     *
     * @throws LogicException when the source is not an ORM manager or does not map the TimeTrack entities
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManager($this->sourceManagerName);
        if (!$source instanceof EntityManagerInterface || $source->getMetadataFactory()->isTransient(TimeEntry::class)) {
            throw new LogicException(sprintf('The Doctrine entity manager "%s" (database.entity_manager, source of nowo_time_track.database.private_entity_manager) does not map %s: map the TimeTrack entities on it (see docs/CONFIGURATION.md).', $this->sourceManagerName, TimeEntry::class));
        }

        return $source;
    }

    /**
     * The source's configuration with the bundle's own repository factory (a copy: the source keeps its own).
     */
    private function configuration(EntityManagerInterface $source): Configuration
    {
        $configuration = clone $source->getConfiguration();
        $configuration->setRepositoryFactory(new TimeTrackRepositoryFactory($source->getConfiguration()->getRepositoryFactory()));

        return $configuration;
    }

    /**
     * The fallback warning of {@see reset()}; a failing logger never breaks the boundary.
     */
    private function logGenericWarning(Throwable $exception): void
    {
        try {
            $this->logger?->warning('TimeTrack: the bundle\'s private entity manager was dropped at a unit-of-work boundary; its pending changes could not be inspected or cleared ({exception}).', [
                'bundle'    => 'time_track',
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Nothing else to report to: the manager is dropped anyway.
        }
    }

    /**
     * Safety net (audits NF-2, ND-L2): a boundary that drops the private manager while it still has inserts, updates or
     * deletes waiting (a TimeTrack entity changed and never saved through the bundle) logs them, per entity class.
     */
    private function warnAboutDroppedChanges(EntityManagerInterface $manager): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = [];
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                $class           = TimeTrackManagerRegistry::realClass($entity::class);
                $changes[$class] = ($changes[$class] ?? 0) + 1;
            }
        }
        if ($changes === []) {
            return;
        }

        ksort($changes);
        $this->logger->warning('TimeTrack: {count} pending change(s) of the bundle\'s private entity manager were dropped at a unit-of-work boundary. Write TimeTrack rows through the TimeTrack services or repositories; the application\'s flush() does not write them.', [
            'bundle'  => 'time_track',
            'count'   => array_sum($changes),
            'changes' => $changes,
        ]);
    }

    /**
     * Scheduled inserts, updates or deletes, including changes not computed yet (a managed entity edited in memory).
     */
    private function hasScheduledWork(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
    }

    /**
     * Mirrors the SQL filters enabled on the source manager, with their parameters, on the private one, and disables
     * the others. A filter whose parameters differ (its state string) is disabled and enabled again (a fresh instance)
     * before its parameters are copied, so a parameter the source no longer has (a previous tenant) never survives.
     * The parameters are read as they are stored (audit NF-7): values, list flag and the list type are kept.
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted  = $source->getFilters()->getEnabledFilters();
        $filters = $target->getFilters();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }
        foreach ($wanted as $name => $filter) {
            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }
                $filters->disable($name);
            }
            // The private configuration is a copy taken when the manager was created: a filter class the source
            // registered later is registered on it too.
            $class = $source->getConfiguration()->getFilterClassName($name);
            if ($class !== null && $target->getConfiguration()->getFilterClassName($name) === null) {
                $target->getConfiguration()->addFilter($name, $class);
            }
            $copy = $filters->enable($name);
            foreach ($this->filterParameters($filter) as $parameter => $value) {
                if ($value->isList) {
                    /** @var array<mixed> $list */
                    $list = $value->value;
                    $copy->setParameterList($parameter, $list, is_string($value->type) ? $value->type : Types::STRING);
                } else {
                    // SQLFilter::setParameter() takes a string type or null: a non-string type was inferred, null infers it again.
                    $copy->setParameter($parameter, $value->value, is_string($value->type) ? $value->type : null);
                }
            }
        }
    }

    /**
     * The raw parameters of a filter (SQLFilter keeps them private and only exposes quoted values).
     *
     * @return array<string, Parameter>
     */
    private function filterParameters(SQLFilter $filter): array
    {
        $read = static fn (SQLFilter $filter): array => $filter->parameters; // @phpstan-ignore property.private (bound to SQLFilter below)
        /** @var array<string, Parameter> $parameters */
        $parameters = Closure::bind($read, null, SQLFilter::class)($filter);

        return $parameters;
    }
}
