<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Doctrine ORM store of the hashed client tokens.
 */
final readonly class DoctrineOrmClientTokenRepository implements ClientTokenRepositoryInterface
{
    /**
     * Receives the entity manager, the registry (worker-mode recovery) and the clock.
     */
    private ClockInterface $clock;

    /**
     * @param EntityManagerInterface $entityManager Entity manager of the configured connection
     * @param ManagerRegistry|null $managerRegistry Resets a closed entity manager after a failed flush (worker mode)
     * @param ClockInterface|null $clock Time source of the expiry checks (defaults to the system clock)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Persists and flushes a token.
     */
    public function save(ClientToken $token): void
    {
        $this->entityManager->persist($token);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Removes and flushes a token.
     */
    public function remove(ClientToken $token): void
    {
        $this->entityManager->remove($token);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Token with this hash, unless expired.
     */
    public function findValidByTokenHash(string $tokenHash): ?ClientToken
    {
        /** @var ClientToken|null $token */
        $token = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(ClientToken::class, 't')
            ->where('t.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$token instanceof ClientToken || $token->isExpired($this->clock->now())) {
            return null;
        }

        return $token;
    }

    /**
     * Deletes the expired tokens in one DQL statement and returns how many were deleted.
     */
    public function purgeExpired(): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(ClientToken::class, 't')
            ->where('t.expiresAt <= :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->execute();
    }
}
