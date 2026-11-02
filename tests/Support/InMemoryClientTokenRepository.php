<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Psr\Clock\ClockInterface;

use function array_values;

final class InMemoryClientTokenRepository implements ClientTokenRepositoryInterface, CredentialClientTokenRepositoryInterface
{
    /** @var array<string, ClientToken> */
    private array $tokens = [];

    /**
     * @param ClockInterface|null $clock same clock as the services under test (null = system time), like DoctrineOrmClientTokenRepository
     */
    public function __construct(private readonly ?ClockInterface $clock = null)
    {
    }

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
            if ($token->getTokenHash() === $tokenHash && !$token->isExpired($this->clock?->now())) {
                return $token;
            }
        }

        return null;
    }

    public function purgeExpired(): int
    {
        $purged = 0;
        foreach ($this->tokens as $id => $token) {
            if ($token->isExpired($this->clock?->now())) {
                unset($this->tokens[$id]);
                ++$purged;
            }
        }

        return $purged;
    }

    public function removeByCredentialId(string $credentialId): int
    {
        $removed = 0;
        foreach ($this->tokens as $id => $token) {
            if ($token->getCredentialId() === $credentialId) {
                unset($this->tokens[$id]);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @return list<ClientToken>
     */
    public function all(): array
    {
        return array_values($this->tokens);
    }
}
