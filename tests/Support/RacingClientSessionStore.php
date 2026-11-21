<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Dto\ClientSessionRecord;
use Nowo\TimeTrackBundle\Repository\ClientSessionRepositoryInterface;

/**
 * A session store where a concurrent revocation deletes the session between the read and the delete of this call.
 */
final readonly class RacingClientSessionStore implements ClientSessionRepositoryInterface
{
    public function __construct(private InMemoryClientTokenRepository $tokens)
    {
    }

    public function findSessionById(string $sessionId): ?ClientSessionRecord
    {
        $session = $this->tokens->findSessionById($sessionId);
        $this->tokens->removeSessionById($sessionId); // The other call wins.

        return $session;
    }

    public function removeSessionById(string $sessionId): int
    {
        return $this->tokens->removeSessionById($sessionId);
    }
}
