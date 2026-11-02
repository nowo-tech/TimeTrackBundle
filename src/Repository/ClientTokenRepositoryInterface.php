<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\ClientToken;

/**
 * Store of the hashed client Bearer tokens.
 */
interface ClientTokenRepositoryInterface
{
    /**
     * Persists a token.
     */
    public function save(ClientToken $token): void;

    /**
     * Deletes a token.
     */
    public function remove(ClientToken $token): void;

    /**
     * Token with this hash, unless expired.
     */
    public function findValidByTokenHash(string $tokenHash): ?ClientToken;

    /**
     * Deletes the expired tokens and returns how many were deleted.
     */
    public function purgeExpired(): int;
}
