<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeMover;

/**
 * Since 1.17: optional store capability for {@see TaskTimeMover}: the entries of tasks that moved to
 * another board get the target board snapshot and scope. Bulk statements only, no flush. The Doctrine store implements it.
 */
interface TaskTimeMoveRepositoryInterface
{
    /**
     * Runs one batch in one transaction, nested into the caller's when there is one: committed when it returns, rolled back
     * and rethrown when it throws.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function inTaskMoveTransaction(callable $operation): mixed;

    /**
     * Entries of these tasks (every source, owner and state), after an id, by id, locked until the transaction ends where the
     * store can. A caller compares the task id again: a collation may fold case.
     *
     * @param list<string> $taskIds
     *
     * @return list<array{id: string, taskId: string, boardId: string|null, scopeKey: string|null, seconds: int|string, startedAt: DateTimeImmutable, personRef: string|null, userId: int|string|null, anonymisedAt: DateTimeImmutable|null}>
     */
    public function findForTaskMove(array $taskIds, string $afterId, int $limit): array;

    /**
     * Sets the board snapshot and the scope of these entries, and nothing else; returns the entries changed. The caller holds
     * them from {@see self::findForTaskMove()}. Loaded entries are detached.
     *
     * @param list<string> $entryIds
     */
    public function applyTaskMove(array $entryIds, ?string $boardId, ?string $scopeKey): int;
}
