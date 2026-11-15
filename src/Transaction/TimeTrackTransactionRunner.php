<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackManagerRegistry;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_filter;
use function is_object;
use function max;
use function min;
use function random_int;
use function spl_object_id;
use function usleep;

/**
 * 1.27.0 (Beacon spec 239): the runner of every TimeTrack write that needs a transaction, registered as
 * `nowo_time_track.transaction_runner` and injected into the bundle's stores and services by its concrete class. The
 * bundle never runs its writes through a runner the application binds to nowo-tech/transaction-contract's
 * {@see TransactionRunnerInterface}: such a runner drives the application's entity manager (it would commit the
 * application's work with a TimeTrack write, and reset that manager after a TimeTrack failure).
 *
 * {@see self::run()} makes one attempt in one transaction (a savepoint inside a transaction the caller opened); a
 * transient failure (deadlock, lock wait timeout, serialization failure, lost savepoint) surfaces as
 * {@see TransientStorageFailure} with a Retry-After hint (503 on the bundle's routes). {@see self::runRetrying()} replays
 * a transient failure of the OUTERMOST transaction a few times with full-jitter backoff (background units of work).
 * After any failure no transaction of the runner stays open: nowo-tech/transaction-doctrine rolls back to the entry
 * level and closes the connection only when that rollback fails (a MySQL / MariaDB deadlock inside a nested flush drops
 * the savepoints), so the worker's next request never inherits a nested connection.
 *
 * The manager's pending work is flushed first, on its own, so a failed TimeTrack unit never rolls it back while the
 * manager believes it is written.
 *
 * - **Private entity manager** (`database.private_entity_manager: true`): nowo-tech/transaction-doctrine's ORM runner on
 *   TimeTrack's own manager. After a storage failure that manager is closed and replaced through the bundle registry;
 *   after a refusal only what the run touched is forgotten. The application's manager is never involved.
 * - **Shared manager** (the default, 1.22 behaviour): the unit runs in a connection-level transaction. After a failure
 *   only the TimeTrack rows the unit added, wrote or changed are detached (and the rows that point at them); the
 *   application's entities stay managed. A failed flush closes the manager (the ORM does): it stays closed for the rest
 *   of the request, so the application's next flush fails loudly instead of silently writing nothing, and
 *   {@see self::reset()} replaces it at the next request, message or command boundary (a FrankenPHP worker never
 *   serves a later request with it). The application's manager is never reset in the middle of a request.
 *
 * Stateless: one instance serves every request of a long-running worker.
 */
final readonly class TimeTrackTransactionRunner implements TransactionRunnerInterface, ResetInterface
{
    /** The service id the bundle services are wired to. */
    public const SERVICE_ID = 'nowo_time_track.transaction_runner';

    /** Attempts of {@see self::runRetrying()} (the first one included). */
    public const RETRY_ATTEMPTS = 3;

    /** Upper bound of the first backoff, in milliseconds; it doubles per attempt (full jitter: 0 to the bound). */
    public const BACKOFF_BASE_MILLISECONDS = 50;

    /** Upper bound of any backoff, in milliseconds. */
    public const BACKOFF_CAP_MILLISECONDS = 1000;

    /**
     * @param EntityManagerInterface $entityManager The manager the TimeTrack rows are written through
     * @param ManagerRegistry|null $registry Replaces that manager (after a failure with the private manager; a closed shared manager at a boundary)
     * @param string|null $managerName The registry name of that manager; null for the default one
     * @param int|null $retryAfterSeconds Retry-After hint of the TransientStorageFailure; null for none
     * @param int $backoffBaseMilliseconds Upper bound of the first backoff of {@see self::runRetrying()} (0 = no wait)
     * @param LoggerInterface|null $logger Warns when a closed shared manager is replaced at a boundary
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?int $retryAfterSeconds = 1,
        private int $backoffBaseMilliseconds = self::BACKOFF_BASE_MILLISECONDS,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function run(callable $operation): mixed
    {
        $manager = $this->entityManager;
        // Pending work first, on its own: a failed TimeTrack unit never rolls it back behind the manager's back.
        $manager->flush();
        if ($manager instanceof TimeTrackEntityManager) {
            // TimeTrack's own manager: transaction-doctrine's ORM runner (a storage failure closes and replaces it, a
            // refusal forgets only what the run touched). The application's manager is not involved.
            return DoctrineTransactionRunner::forEntityManager($manager, $this->registry, $this->managerName, $this->retryAfterSeconds)->run($operation);
        }

        // The application's manager (shared): transaction-doctrine's ORM runner would close and reset it (or clear it)
        // after a storage failure, detaching the application's entities behind its back. The unit runs in a
        // connection-level transaction instead, and only the TimeTrack rows it touched are forgotten.
        return $this->onConnection(static function () use ($operation, $manager): mixed {
            $result = $operation();
            $manager->flush();

            return $result;
        });
    }

    /**
     * A unit of work of statements only (bulk DQL, DBAL; no entity to write): one transaction on the connection of the
     * TimeTrack rows, one attempt, and no entity manager is flushed (the application's pending work stays pending, as
     * before 1.27). After a failure the TimeTrack rows the unit loaded or changed are forgotten (with the private
     * manager, the rollback makes it replace itself).
     *
     * @template T
     *
     * @param callable(): T $operation The unit of work
     *
     * @throws TransientStorageFailure When the storage failed transiently (nothing of the unit is committed)
     *
     * @return T What the operation returned
     */
    public function runStatements(callable $operation): mixed
    {
        return $this->onConnection($operation);
    }

    /**
     * Same as {@see self::run()}, but a transient failure of the OUTERMOST transaction is replayed up to `$attempts`
     * times in all, after a full-jitter backoff. Inside a transaction the caller opened it is not replayed: the storage
     * may have rolled back the caller's work as well (a MySQL deadlock rolls back the whole transaction), so the failure
     * goes to the caller. With the shared manager a failure that closed the manager is not replayed either (the manager
     * is replaced only at the next boundary). The operation must be replayable: it loads what it changes, and its side
     * effects (events, mails) wait until this method returned.
     *
     * @template T
     *
     * @param callable(): T $operation The unit of work; replayable
     * @param int $attempts Attempts in all (at least one)
     * @param bool $statementsOnly Each attempt as {@see self::runStatements()} (no entity manager flushed)
     *
     * @throws TransientStorageFailure When the last attempt failed transiently, or a nested one did
     *
     * @return T What the operation returned
     */
    public function runRetrying(callable $operation, int $attempts = self::RETRY_ATTEMPTS, bool $statementsOnly = false): mixed
    {
        $attempts = max(1, $attempts);
        for ($attempt = 1;; ++$attempt) {
            $outermost = !$this->entityManager->getConnection()->isTransactionActive();
            try {
                return $statementsOnly ? $this->runStatements($operation) : $this->run($operation);
            } catch (TransientStorageFailure $failure) {
                if (!$outermost || $attempt >= $attempts || !$this->entityManager->isOpen()) {
                    throw $failure;
                }
                $this->backoff($attempt);
            }
        }
    }

    /**
     * Whether a transaction is open on the connection of the TimeTrack rows (a unit of work then joins it).
     */
    public function isTransactionActive(): bool
    {
        return $this->entityManager->getConnection()->isTransactionActive();
    }

    /**
     * At a request, message or command boundary: a shared manager that a failed TimeTrack write closed is replaced, so
     * the next request of a long-running worker starts with an open one. Nothing happens to an open manager, or with
     * the private manager (the bundle replaces its own).
     */
    public function reset(): void
    {
        if ($this->entityManager instanceof TimeTrackEntityManager || !$this->registry instanceof ManagerRegistry || $this->entityManager->isOpen()) {
            return;
        }
        $this->registry->resetManager($this->managerName);
        $this->logger?->warning('TimeTrack: the entity manager closed by a failed write was replaced at the unit-of-work boundary.', ['bundle' => 'time_track']);
    }

    /**
     * One connection-level transaction (transaction-doctrine's connection runner); after a failure the TimeTrack rows
     * the unit touched are forgotten when the manager is still open.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function onConnection(callable $operation): mixed
    {
        $manager = $this->entityManager;
        $before  = $this->bundleRows($manager);
        try {
            return DoctrineTransactionRunner::forConnection($manager->getConnection(), $this->retryAfterSeconds)->run($operation);
        } catch (Throwable $failure) {
            // A manager the ORM closed in a failed flush stays closed: loud for the application (see reset()).
            if ($manager->isOpen()) {
                $this->forgetBundleRows($manager, $before);
            }

            throw $failure;
        }
    }

    /**
     * Sleeps a random time between 0 and the attempt's bound (full jitter: concurrent losers do not retry in lockstep).
     */
    private function backoff(int $attempt): void
    {
        $bound = min(self::BACKOFF_CAP_MILLISECONDS, $this->backoffBaseMilliseconds << ($attempt - 1));
        if ($bound > 0) {
            usleep(random_int(0, $bound) * 1000);
        }
    }

    /**
     * The TimeTrack rows the manager holds, with the data it knows them by (before a unit of work).
     *
     * @return array<int, array<string, mixed>> object id => original data
     */
    private function bundleRows(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $rows       = [];
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if (TimeTrackManagerRegistry::isBundleEntity($class)) {
                foreach ($entities as $entity) {
                    $rows[spl_object_id($entity)] = $unitOfWork->getOriginalEntityData($entity);
                }
            }
        }

        return $rows;
    }

    /**
     * After a failed unit: detaches the TimeTrack rows it added, wrote or changed (their database state was rolled
     * back); a TimeTrack row the caller held and the unit did not change stays managed, and so does every entity of the
     * application. A later TimeTrack call loads its rows again.
     *
     * @param array<int, array<string, mixed>> $before {@see self::bundleRows()} before the unit
     */
    private function forgetBundleRows(EntityManagerInterface $manager, array $before): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $rows       = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if (TimeTrackManagerRegistry::isBundleEntity($class)) {
                foreach ($entities as $entity) {
                    $rows[] = $entity;
                }
            }
        }
        $stale = [];
        $kept  = [];
        foreach (array_filter($rows, static fn (object $entity): bool => TimeTrackManagerRegistry::isBundleEntity($entity::class) && $manager->contains($entity)) as $entity) {
            $id = spl_object_id($entity);
            if (!isset($before[$id]) || $before[$id] !== $unitOfWork->getOriginalEntityData($entity) || $this->changed($manager, $entity)) {
                $stale[$id] = $entity;
            } else {
                $kept[$id] = $entity;
            }
        }
        // A kept row that points at a stale one goes too (it would drag it back as a new entity on the next flush).
        do {
            $more = false;
            foreach ($kept as $id => $entity) {
                $metadata = $manager->getClassMetadata($entity::class);
                foreach ($metadata->associationMappings as $field => $mapping) {
                    $target = $mapping->isToOne() ? $metadata->getFieldValue($entity, $field) : null;
                    if (is_object($target) && isset($stale[spl_object_id($target)])) {
                        $stale[$id] = $entity;
                        unset($kept[$id]);
                        $more = true;

                        break;
                    }
                }
            }
        } while ($more);
        foreach ($stale as $entity) {
            $manager->detach($entity);
        }
    }

    /**
     * Whether the row has changes the manager would write (the ORM's own comparison with the data it knows it by).
     */
    private function changed(EntityManagerInterface $manager, object $entity): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSet($manager->getClassMetadata($entity::class), $entity);

        return $unitOfWork->getEntityChangeSet($entity) !== [];
    }
}
