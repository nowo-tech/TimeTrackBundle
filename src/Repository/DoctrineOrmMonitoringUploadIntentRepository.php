<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackSavepoint;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\UserRef\ExactUserIds;

use function in_array;

/**
 * Since 1.26.0: Doctrine ORM store of the screenshot upload intents, through the bundle's entity manager (the private
 * one with `database.private_entity_manager: true`). Reads use `HINT_REFRESH` and detach what they return, so a
 * kept-alive worker never answers from a row an earlier request loaded (FrankenPHP worker mode, kernel reset false).
 *
 * @internal
 */
final readonly class DoctrineOrmMonitoringUploadIntentRepository implements MonitoringUploadIntentRepositoryInterface
{
    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager
     * @param ManagerRegistry|null $managerRegistry Resets a closed entity manager after a failed flush (worker mode)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /** Persists, flushes and detaches a new intent. */
    public function save(MonitoringUploadIntent $intent): void
    {
        $this->entityManager->persist($intent);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
        $this->entityManager->detach($intent);
    }

    /** The user's intent by id. */
    public function findForUser(string $id, string $userId): ?MonitoringUploadIntent
    {
        return $this->one(['id' => $id], $userId);
    }

    /** The user's intent by hashed key. */
    public function findByKey(string $userId, string $idempotencyHash): ?MonitoringUploadIntent
    {
        return $this->one(['idempotencyHash' => $idempotencyHash], $userId);
    }

    /** Whether another live intent holds the slot. */
    public function slotTaken(string $userId, string $entryId, DateTimeImmutable $slot, string $exceptId, DateTimeImmutable $now): bool
    {
        /** @var list<MonitoringUploadIntent> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(MonitoringUploadIntent::class, 'i')
            ->where('IDENTITY(i.user) = :user')
            ->andWhere('i.entryId = :entry')
            ->andWhere('i.slot = :slot')
            ->andWhere('i.id <> :except')
            ->setParameter('user', $userId)
            ->setParameter('entry', $entryId)
            ->setParameter('slot', StorageTime::normalize($slot), 'datetime_immutable')
            ->setParameter('except', $exceptId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        $taken = false;
        foreach (ExactUserIds::filter($rows, [$userId], static fn (MonitoringUploadIntent $row): ?string => UserIdResolver::idOf($row->getUser())) as $row) {
            $until = $row->getSignedUntil();
            $live  = in_array($row->getStatus(), [MonitoringUploadIntent::STATUS_UPLOADING, MonitoringUploadIntent::STATUS_STORED], true)
                || ($row->getStatus() === MonitoringUploadIntent::STATUS_PENDING && $until !== null && $until->getTimestamp() > $now->getTimestamp());
            if ($row->getEntryId() === $entryId && $live) {
                $taken = true;
            }
        }
        foreach ($rows as $row) {
            $this->entityManager->detach($row);
        }

        return $taken;
    }

    /** Records the expiry of a new URL. */
    public function sign(string $id, DateTimeImmutable $until, DateTimeImmutable $staleBefore): bool
    {
        return TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->update(MonitoringUploadIntent::class, 'i')
            ->set('i.signedUntil', ':until')
            ->set('i.status', ':pending')
            ->where('i.id = :id')
            ->andWhere('i.status IN (:pending, :spent) OR (i.status = :uploading AND i.signedUntil < :stale)')
            ->setParameter('until', StorageTime::normalize($until), 'datetime_immutable')
            ->setParameter('pending', MonitoringUploadIntent::STATUS_PENDING)
            ->setParameter('spent', MonitoringUploadIntent::STATUS_SPENT)
            ->setParameter('uploading', MonitoringUploadIntent::STATUS_UPLOADING)
            ->setParameter('stale', StorageTime::normalize($staleBefore), 'datetime_immutable')
            ->setParameter('id', $id)
            ->getQuery(), $this->entityManager) === 1;
    }

    /** Pending → uploading for the URL that expires at `$signedUntil`. */
    public function consume(string $id, DateTimeImmutable $signedUntil, DateTimeImmutable $now): bool
    {
        if ($signedUntil->getTimestamp() <= $now->getTimestamp()) {
            return false;
        }

        return TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->update(MonitoringUploadIntent::class, 'i')
            ->set('i.status', ':uploading')
            ->where('i.id = :id')
            ->andWhere('i.status = :pending')
            ->andWhere('i.signedUntil = :until')
            ->setParameter('uploading', MonitoringUploadIntent::STATUS_UPLOADING)
            ->setParameter('pending', MonitoringUploadIntent::STATUS_PENDING)
            ->setParameter('until', StorageTime::normalize($signedUntil), 'datetime_immutable')
            ->setParameter('id', $id)
            ->getQuery(), $this->entityManager) === 1;
    }

    /** Uploading → spent. */
    public function release(string $id): void
    {
        TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->update(MonitoringUploadIntent::class, 'i')
            ->set('i.status', ':spent')
            ->where('i.id = :id')
            ->andWhere('i.status = :uploading')
            ->setParameter('spent', MonitoringUploadIntent::STATUS_SPENT)
            ->setParameter('uploading', MonitoringUploadIntent::STATUS_UPLOADING)
            ->setParameter('id', $id)
            ->getQuery(), $this->entityManager);
    }

    /** Uploading → stored. */
    public function markStored(string $id, string $screenshotId): void
    {
        TimeTrackSavepoint::execute($this->entityManager->createQueryBuilder()
            ->update(MonitoringUploadIntent::class, 'i')
            ->set('i.status', ':stored')
            ->set('i.screenshotId', ':screenshot')
            ->where('i.id = :id')
            ->andWhere('i.status = :uploading')
            ->setParameter('stored', MonitoringUploadIntent::STATUS_STORED)
            ->setParameter('screenshot', $screenshotId)
            ->setParameter('uploading', MonitoringUploadIntent::STATUS_UPLOADING)
            ->setParameter('id', $id)
            ->getQuery(), $this->entityManager);
    }

    /** Deletes old intents. */
    public function purgeCreatedBefore(DateTimeImmutable $before, ?string $userId = null): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->delete(MonitoringUploadIntent::class, 'i')
            ->where('i.createdAt < :before')
            ->setParameter('before', StorageTime::normalize($before), 'datetime_immutable');
        if ($userId !== null) {
            // Only this user's rows: a look-alike id may delete nothing it should not (old rows only, and the
            // owner's own replay window is what is lost at worst).
            $query->andWhere('IDENTITY(i.user) = :user')->setParameter('user', $userId);
        }

        return (int) TimeTrackSavepoint::execute($query->getQuery(), $this->entityManager);
    }

    /**
     * @param array<string, string> $criteria
     */
    private function one(array $criteria, string $userId): ?MonitoringUploadIntent
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(MonitoringUploadIntent::class, 'i')
            ->where('IDENTITY(i.user) = :user')
            ->setParameter('user', $userId);
        foreach ($criteria as $field => $value) {
            $builder->andWhere('i.' . $field . ' = :' . $field)->setParameter($field, $value);
        }
        /** @var list<MonitoringUploadIntent> $rows */
        $rows  = $builder->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $found = null;
        foreach (ExactUserIds::filter($rows, [$userId], static fn (MonitoringUploadIntent $row): ?string => UserIdResolver::idOf($row->getUser())) as $row) {
            if (($criteria['id'] ?? $row->getId()) === $row->getId() && ($criteria['idempotencyHash'] ?? $row->getIdempotencyHash()) === $row->getIdempotencyHash()) {
                $found = $row;
            }
        }
        foreach ($rows as $row) {
            $this->entityManager->detach($row);
        }

        return $found;
    }
}
