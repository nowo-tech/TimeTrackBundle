<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
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
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /** Persists and flushes a request. */
    public function save(DeviceAuthorization $authorization): void
    {
        $this->entityManager->persist($authorization);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /** Removes and flushes a request. */
    public function remove(DeviceAuthorization $authorization): void
    {
        $this->entityManager->remove($authorization);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
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
        $updated = $this->entityManager->createQueryBuilder()
            ->update(DeviceAuthorization::class, 'd')
            ->set('d.status', ':consumed')
            ->where('d.id = :id')
            ->andWhere('d.status = :approved')
            ->setParameter('consumed', DeviceAuthorizationStatus::Consumed->value)
            ->setParameter('approved', DeviceAuthorizationStatus::Approved->value)
            ->setParameter('id', $authorization->getId())
            ->getQuery()
            ->execute();

        return $updated === 1;
    }

    /** Deletes the expired requests in one DQL statement. */
    public function purgeExpired(): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(DeviceAuthorization::class, 'd')
            ->where('d.expiresAt <= :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->execute();
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
