<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

/**
 * Bulk revocation of the session tokens of a host credential (1.9). Kept apart from
 * {@see ClientTokenRepositoryInterface} so host decorators of that interface keep compiling.
 */
interface CredentialClientTokenRepositoryInterface
{
    /**
     * Deletes every token issued from the credential and returns how many were deleted.
     */
    public function removeByCredentialId(string $credentialId): int;
}
