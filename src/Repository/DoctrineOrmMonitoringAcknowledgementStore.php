<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Doctrine\Common\Collections\Order as SortDirection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\MonitoringAcknowledgementRecord;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgementStoreInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\UserRef\ExactUserIds;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.26.0 (contract §24.6): the standard default {@see MonitoringAcknowledgementStoreInterface} — one
 * {@see MonitoringAcknowledgementRecord} per user and version, written through the bundle's entity manager. Reads are
 * fresh and detached (FrankenPHP worker mode, kernel reset false). A DDD host aliases the interface to its own store.
 */
final readonly class DoctrineOrmMonitoringAcknowledgementStore implements MonitoringAcknowledgementStoreInterface
{
    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager
     * @param UserReferenceResolverInterface $userReferences The stored `user` of the row
     * @param ManagerRegistry|null $managerRegistry Resets a closed entity manager after a failed flush (worker mode)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserReferenceResolverInterface $userReferences,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * Stores the acknowledgement unless the user already acknowledged that version (the first record is kept; a
     * concurrent duplicate is ignored).
     */
    public function acknowledge(TimeTrackUserRef $user, MonitoringAcknowledgement $acknowledgement): void
    {
        $security = TimeTrackUserRefs::securityUser($user, MonitoringAcknowledgementStoreInterface::class);
        if ($user->id === null || $this->records($user->id, $acknowledgement->policyVersion) !== []) {
            return;
        }
        $record = new MonitoringAcknowledgementRecord(
            $this->userReferences->reference($security),
            $acknowledgement->policyVersion,
            $acknowledgement->acknowledgedAt,
            $acknowledgement->clientType,
            $acknowledgement->noticeSha256,
        );
        $this->entityManager->persist($record);
        try {
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
            // @codeCoverageIgnoreStart
        } catch (UniqueConstraintViolationException) {
            return; // A concurrent request stored the same version between the read and the flush: the first record is kept.
        }
        // @codeCoverageIgnoreEnd
        $this->entityManager->detach($record);
    }

    /**
     * The acknowledgement of the highest version.
     */
    public function lastAcknowledged(TimeTrackUserRef $user): ?MonitoringAcknowledgement
    {
        if ($user->id === null) {
            return null;
        }
        $records = $this->records($user->id, null);

        return ($records[0] ?? null)?->toAcknowledgement();
    }

    /**
     * The user's records (of one version, or all), highest version first; detached.
     *
     * @return list<MonitoringAcknowledgementRecord>
     */
    private function records(string $userId, ?int $version): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(MonitoringAcknowledgementRecord::class, 'r')
            ->where('IDENTITY(r.user) = :user')
            ->setParameter('user', $userId)
            ->orderBy('r.policyVersion', SortDirection::Descending->value);
        if ($version !== null) {
            $builder->andWhere('r.policyVersion = :version')->setParameter('version', $version);
        }
        /** @var list<MonitoringAcknowledgementRecord> $rows */
        $rows = $builder->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $kept = ExactUserIds::filter($rows, [$userId], static fn (MonitoringAcknowledgementRecord $row): ?string => UserIdResolver::idOf($row->getUser()));
        foreach ($rows as $row) {
            $this->entityManager->detach($row);
        }

        return $kept;
    }
}
