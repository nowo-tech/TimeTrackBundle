<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

/**
 * Since 1.35.0: bulk revocation of every client session of one user ("sign out everywhere"). Kept apart from
 * {@see ClientTokenRepositoryInterface} and {@see CredentialClientTokenRepositoryInterface} so host decorators of those
 * interfaces keep compiling.
 */
interface UserClientTokenRepositoryInterface
{
    /**
     * Deletes every token of the user (byte-exact user id) and returns how many were deleted.
     */
    public function removeByUserId(string $userId): int;
}
