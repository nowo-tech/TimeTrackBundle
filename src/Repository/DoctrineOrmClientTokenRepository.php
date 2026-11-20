<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackHostFlushProbe;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Dto\ClientSessionRecord;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function is_array;
use function is_scalar;

/**
 * Doctrine ORM store of the hashed client tokens.
 */
final readonly class DoctrineOrmClientTokenRepository implements ClientTokenRepositoryInterface, CredentialClientTokenRepositoryInterface, ClientSessionRepositoryInterface
{
    /**
     * Receives the entity manager, the registry (worker-mode recovery) and the clock.
     */
    private ClockInterface $clock;

    /**
     * @param EntityManagerInterface $entityManager Entity manager of the configured connection
     * @param ManagerRegistry|null $managerRegistry Resets a closed entity manager after a failed flush (worker mode)
     * @param ClockInterface|null $clock Time source of the expiry checks (defaults to the system clock)
     * @param TimeTrackHostFlushProbe|null $hostFlushProbe 1.33.0: the probe of the shared manager (null with the private one)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        ?ClockInterface $clock = null,
        private ?TimeTrackHostFlushProbe $hostFlushProbe = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Persists and flushes a token.
     */
    public function save(ClientToken $token): void
    {
        $this->entityManager->persist($token);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry, $this->hostFlushProbe);
    }

    /**
     * Removes and flushes a token.
     */
    public function remove(ClientToken $token): void
    {
        $this->entityManager->remove($token);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry, $this->hostFlushProbe);
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
        return TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->delete(ClientToken::class, 't')
            ->where('t.expiresAt <= :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery(), $this->entityManager);
    }

    /**
     * Deletes the tokens of a credential in one DQL statement and returns how many were deleted.
     */
    public function removeByCredentialId(string $credentialId): int
    {
        return TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->delete(ClientToken::class, 't')
            ->where('t.credentialId = :credential')
            ->setParameter('credential', $credentialId)
            ->getQuery(), $this->entityManager);
    }

    /**
     * Since 1.34.0: the session with exactly this id, read as scalars (no entity enters the manager). A row whose id
     * differs from the one asked for (a case-insensitive collation) is not that session.
     */
    public function findSessionById(string $sessionId): ?ClientSessionRecord
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id', 'IDENTITY(t.user) AS ownerId', 't.credentialId AS credentialId', 't.clientType AS clientType')
            ->from(ClientToken::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $sessionId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!is_array($row) || $row['id'] !== $sessionId || !is_scalar($row['ownerId'])) {
            return null;
        }
        $clientType   = $row['clientType'];
        $credentialId = $row['credentialId'];

        return new ClientSessionRecord(
            $sessionId,
            (string) $row['ownerId'],
            is_scalar($credentialId) ? (string) $credentialId : null,
            $clientType instanceof ClientType ? $clientType : ClientType::from(is_scalar($clientType) ? (string) $clientType : ''),
        );
    }

    /**
     * Since 1.34.0: deletes the session with this id in one DQL statement (nothing is flushed) and returns how many rows
     * were deleted.
     */
    public function removeSessionById(string $sessionId): int
    {
        return (int) TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->delete(ClientToken::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $sessionId)
            ->getQuery(), $this->entityManager);
    }
}
