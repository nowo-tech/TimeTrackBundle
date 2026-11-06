<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

/**
 * Since the GG fixes (audit GG-L1): optional store capability — run a write of one user's time in one transaction that
 * holds a per-user write lock, so overlap checks and inserts of concurrent requests of the same user never interleave.
 * The Doctrine store locks the user row (`SELECT … FOR UPDATE`; SQLite serialises writers itself). Stores without it run
 * the operation as is.
 */
interface UserSerialisedTimeEntryRepositoryInterface
{
    /**
     * Runs the operation in one transaction holding the user's write lock: committed when it returns, rolled back
     * (and rethrown) when it throws.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function inUserTransaction(object $user, callable $operation): mixed;
}
