<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;

final class InMemoryClientTokenRepository implements ClientTokenRepositoryInterface
{
    /** @var array<string, ClientToken> */
    private array $tokens = [];

    public function save(ClientToken $token): void
    {
        $this->tokens[$token->getId()] = $token;
    }

    public function remove(ClientToken $token): void
    {
        unset($this->tokens[$token->getId()]);
    }

    public function findValidByTokenHash(string $tokenHash): ?ClientToken
    {
        foreach ($this->tokens as $token) {
            if ($token->getTokenHash() === $tokenHash && !$token->isExpired()) {
                return $token;
            }
        }

        return null;
    }

    public function purgeExpired(): int
    {
        $purged = 0;
        foreach ($this->tokens as $id => $token) {
            if ($token->isExpired()) {
                unset($this->tokens[$id]);
                ++$purged;
            }
        }

        return $purged;
    }
}
