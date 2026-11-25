<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackRows;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Nowo\TimeTrackBundle\UserRef\ExactUserIds;

use function array_column;

/**
 * Doctrine ORM store of the running timers.
 */
final readonly class DoctrineOrmActiveTimerRepository implements ConditionalActiveTimerRepositoryInterface, TaskTimerMoveRepositoryInterface
{
    /**
     * Receives the entity manager, its registry and (since 1.27.0) the bundle's transaction runner.
     */
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        private ?TimeTrackTransactionRunner $runner = null,
        ?object $hostFlushProbe = null,
    ) {
    }

    /**
     * Persists and flushes a timer.
     *
     * Since 1.27.0 (Beacon spec 239) a new timer is written through DBAL (in a savepoint inside a transaction) and then
     * managed, so a concurrent start that took the user's unique `user_id` slot first makes this throw the unique
     * violation with the entity manager still open: up to 1.26 the failed flush closed it, and the bundle reset it (the
     * application's manager by default) in the middle of the request.
     *
     * @throws UniqueConstraintViolationException when the user already has a running timer (a concurrent start won)
     */
    public function save(ActiveTimer $timer): void
    {
        if ($this->entityManager->contains($timer)) {
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);

            return;
        }
        $lost = TimeTrackRows::insert($this->entityManager, $timer, true);
        if ($lost instanceof UniqueConstraintViolationException) {
            throw $lost;
        }
    }

    /**
     * Removes and flushes a timer.
     */
    public function remove(ActiveTimer $timer): void
    {
        $this->entityManager->remove($timer);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Conditional `DELETE … WHERE id = :id` in one unit of work of the bundle's runner: only the call whose delete affects
     * one row runs `$onRemoved`; a concurrent call blocks on the row (MySQL / PostgreSQL) or the database lock (SQLite)
     * and then deletes nothing. Since 1.27.0 (Beacon spec 239) the row is locked and read again first, and `$onRemoved`
     * receives that timer: what a task move or a note committed meanwhile is what the time entry records. The timer is
     * detached afterwards so a later flush does not touch the deleted row. A deadlock or lock wait timeout surfaces as
     * `TransientStorageFailure` (nothing removed, nothing recorded).
     */
    public function removeIfPresent(ActiveTimer $timer, callable $onRemoved): bool
    {
        $id      = $timer->getId();
        $removed = $this->runner()->run(function () use ($id, $onRemoved): ?ActiveTimer {
            $current = $this->lockById($id);
            if (!$current instanceof ActiveTimer) {
                return null;
            }
            $deleted = TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
                ->delete(ActiveTimer::class, 't')
                ->where('t.id = :id')
                ->setParameter('id', $id)
                ->getQuery(), $this->entityManager);
            if ($deleted !== 1) {
                // @codeCoverageIgnoreStart
                return null; // Only without row locks (a store on a platform without SELECT … FOR UPDATE).
                // @codeCoverageIgnoreEnd
            }
            $onRemoved($current);

            return $current;
        });

        foreach ([$timer, $removed] as $loaded) {
            if ($loaded instanceof ActiveTimer && $this->entityManager->contains($loaded)) {
                $this->entityManager->detach($loaded);
            }
        }

        return $removed instanceof ActiveTimer;
    }

    /**
     * Since 1.27.0: the timer row locked (`SELECT … FOR UPDATE`) and read again inside the caller's transaction (a
     * managed instance is refreshed in place); null when it is gone.
     */
    public function lockById(string $id): ?ActiveTimer
    {
        /** @var ActiveTimer|null $timer */
        $timer = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(ActiveTimer::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $timer instanceof ActiveTimer && $timer->getId() === $id ? $timer : null;
    }

    /**
     * Since 1.27.0: the bundle's transaction runner of this store.
     */
    public function runner(): TimeTrackTransactionRunner
    {
        return $this->runner ?? new TimeTrackTransactionRunner($this->entityManager, $this->managerRegistry);
    }

    /**
     * Running timer of a user.
     */
    public function findByUserId(string $userId): ?ActiveTimer
    {
        if ($userId === '') {
            // A user without an id (persisted but not flushed yet) has no timer; the write that follows refuses it
            // clearly (TimeTrackEntityManager, W1-2) instead of a database type error on the empty id.
            return null;
        }
        /** @var ActiveTimer|null $timer */
        $timer = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(ActiveTimer::class, 't')
            ->innerJoin('t.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        // Since 1.23.0 (id user mapping): the owner id must match byte for byte (audit LQ).
        return ExactUserIds::keepOne($this->entityManager, ActiveTimer::class, $timer, $userId);
    }

    /**
     * Since 1.17: sets the board snapshot of the running timers of these tasks that do not have it yet, by id; nothing else
     * changes; with `$fromBoardId`, only timers on that board. A loaded timer is detached, so a later flush does not write its old board back.
     */
    public function moveTimersToBoard(array $taskIds, ?string $boardId, ?string $fromBoardId = null): int
    {
        if ($taskIds === []) {
            return 0;
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id')
            ->from(ActiveTimer::class, 't')
            ->where('t.taskId IN (:taskIds)')
            ->setParameter('taskIds', $taskIds);
        if ($fromBoardId !== null) {
            $query->andWhere('t.boardIdSnapshot = :fromBoardId')->setParameter('fromBoardId', $fromBoardId);
        }
        if ($boardId === null) {
            $query->andWhere('t.boardIdSnapshot IS NOT NULL');
        } else {
            $query->andWhere('(t.boardIdSnapshot IS NULL OR t.boardIdSnapshot <> :boardId)')->setParameter('boardId', $boardId);
        }
        /** @var list<string> $ids */
        $ids = array_column($query->getQuery()->getArrayResult(), 'id');
        if ($ids === []) {
            return 0;
        }
        $changed = (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->update(ActiveTimer::class, 't')
            ->set('t.boardIdSnapshot', ':boardId')
            ->where('t.id IN (:ids)')
            ->setParameter('boardId', $boardId)
            ->setParameter('ids', $ids)
            ->getQuery(), $this->entityManager);
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($ids as $id) {
            $loaded = $unitOfWork->tryGetById($id, ActiveTimer::class);
            if ($loaded instanceof ActiveTimer) {
                $this->entityManager->detach($loaded);
            }
        }

        return $changed;
    }
}
