<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Dto\ClientSessionRecord;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Since 1.34.0: one client session by its id, for {@see ClientTokenRevoker::revokeById()}.
 * Kept apart from {@see ClientTokenRepositoryInterface} so host decorators of that interface keep compiling.
 */
interface ClientSessionRepositoryInterface
{
    /**
     * The session with exactly this id (expired or not), or null. Loads no entity.
     */
    public function findSessionById(string $sessionId): ?ClientSessionRecord;

    /**
     * Deletes the session with exactly this id in one statement and returns how many rows were deleted (0 or 1; 0 when a
     * concurrent call deleted it first).
     *
     * @throws TransientStorageFailure When the storage failed transiently (nothing committed)
     */
    public function removeSessionById(string $sessionId): int;
}
