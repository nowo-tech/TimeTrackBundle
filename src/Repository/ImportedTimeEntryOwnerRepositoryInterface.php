<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;

/**
 * Since 1.16: optional store capability for moving imported entries between owners. Bulk statements only, no entity loaded,
 * no flush. The Doctrine store implements it.
 */
interface ImportedTimeEntryOwnerRepositoryInterface
{
    /**
     * Runs the operation in one transaction, nested into the caller's when there is one: committed when it returns, rolled
     * back and rethrown when it throws.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function inOwnerMoveTransaction(callable $operation): mixed;

    /**
     * Imported entries of exactly this source held by this owner (a user id, or else a person reference with no user),
     * not anonymised, after an id, by id, locked until the transaction ends where the store can. A caller compares the source and
     * ids again: a collation may fold case.
     *
     * @param list<string>|null $externalIds only these, when given
     *
     * @return list<array{id: string, externalSource: string, externalId: string, taskId: string, boardId: string|null, scopeKey: string|null, seconds: int|string, startedAt: DateTimeImmutable, personRef: string|null}>
     */
    public function findForOwnerMove(string $externalSource, ?string $fromUserId, ?string $fromPersonRef, ?array $externalIds, string $afterId, int $limit): array;

    /**
     * Gives these entries a new owner; returns the entries changed. The caller holds them from {@see self::findForOwnerMove()}, whose
     * lock and check are what guarantee they still have the old owner. A user clears
     * the person; no user sets the reference and name. Seconds, times, task and external id do not change. Loaded entries
     * are detached.
     *
     * @param list<string> $entryIds
     */
    public function applyOwnerMove(array $entryIds, ?string $fromUserId, ?string $fromPersonRef, ?object $toUser, ?string $toPersonRef, ?string $toPersonName): int;
}
