<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Doctrine ORM store of the device authorization requests.
 */
final readonly class DoctrineOrmDeviceAuthorizationRepository implements DeviceAuthorizationRepositoryInterface
{
    private ClockInterface $clock;

    /**
     * @param EntityManagerInterface $entityManager Entity manager of the configured connection
     * @param ManagerRegistry|null $managerRegistry Resets a closed entity manager after a failed flush (worker mode)
     * @param ClockInterface|null $clock Time source of the expiry checks
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
        ?ClockInterface $clock = null,
        private ?TimeTrackTransactionRunner $runner = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Since 1.27.0: the bundle's transaction runner of this store.
     */
    public function runner(): TimeTrackTransactionRunner
    {
        return $this->runner ?? new TimeTrackTransactionRunner($this->entityManager, $this->managerRegistry);
    }

    /**
     * Since 1.27.0 (Beacon spec 239): the request locked (`SELECT … FOR UPDATE`) and read again inside the caller's
     * transaction (a managed instance is refreshed in place); null when it is gone.
     */
    public function lockById(string $id): ?DeviceAuthorization
    {
        /** @var DeviceAuthorization|null $authorization */
        $authorization = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(DeviceAuthorization::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $authorization instanceof DeviceAuthorization && $authorization->getId() === $id ? $authorization : null;
    }

    /** Persists and flushes a request. */
    public function save(DeviceAuthorization $authorization): void
    {
        $this->entityManager->persist($authorization);
        RecoveringFlusher::flush($this->entityManager);
    }

    /** Removes and flushes a request. */
    public function remove(DeviceAuthorization $authorization): void
    {
        $this->entityManager->remove($authorization);
        RecoveringFlusher::flush($this->entityManager);
    }

    /** Request with this device code hash. */
    public function findByDeviceCodeHash(string $deviceCodeHash): ?DeviceAuthorization
    {
        return $this->entityManager->getRepository(DeviceAuthorization::class)->findOneBy(['deviceCodeHash' => $deviceCodeHash]);
    }

    /** Pending, not expired request with this user code hash. */
    public function findPendingByUserCodeHash(string $userCodeHash): ?DeviceAuthorization
    {
        return $this->pending(['userCodeHash' => $userCodeHash]);
    }

    /** Pending, not expired request by id. */
    public function findPendingById(string $id): ?DeviceAuthorization
    {
        return $this->pending(['id' => $id]);
    }

    /** Conditional DQL update approved → consumed; true when this call won. */
    public function markConsumed(DeviceAuthorization $authorization): bool
    {
        $updated = TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->update(DeviceAuthorization::class, 'd')
            ->set('d.status', ':consumed')
            ->where('d.id = :id')
            ->andWhere('d.status = :approved')
            ->setParameter('consumed', DeviceAuthorizationStatus::Consumed->value)
            ->setParameter('approved', DeviceAuthorizationStatus::Approved->value)
            ->setParameter('id', $authorization->getId())
            ->getQuery(), $this->entityManager);

        return $updated === 1;
    }

    /** Deletes the expired requests in one DQL statement. */
    public function purgeExpired(): int
    {
        return TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->delete(DeviceAuthorization::class, 'd')
            ->where('d.expiresAt <= :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery(), $this->entityManager);
    }

    /** Counts the requests that have not expired yet in one DQL statement. */
    public function countUnexpired(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(DeviceAuthorization::class, 'd')
            ->where('d.expiresAt > :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array<string, string> $criteria
     */
    private function pending(array $criteria): ?DeviceAuthorization
    {
        $authorization = $this->entityManager->getRepository(DeviceAuthorization::class)->findOneBy($criteria + ['status' => DeviceAuthorizationStatus::Pending]);

        return $authorization instanceof DeviceAuthorization && !$authorization->isExpired($this->clock->now()) ? $authorization : null;
    }
}
