<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\Common\Collections\Order as SortDirection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionStatus;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\CalendarSuggestionStatus;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Throwable;

use function array_fill_keys;
use function array_map;
use function is_array;
use function is_string;
use function max;

/**
 * Since 1.14: Doctrine ORM store of the calendar connections, suggestions and rules (always mapped, contract §3.9).
 */
final readonly class DoctrineOrmCalendarRepository implements CalendarConnectionRepositoryInterface, CalendarSuggestionRepositoryInterface, CalendarRuleRepositoryInterface
{
    /**
     * Receives the entity manager (and the registry that resets it after a failed flush in worker mode).
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * Persists and flushes a connection.
     */
    public function saveConnection(CalendarConnection $connection): void
    {
        $this->entityManager->persist($connection);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * The connection with this id.
     */
    public function findConnection(string $id): ?CalendarConnection
    {
        return $this->entityManager->find(CalendarConnection::class, $id);
    }

    /**
     * The connection of a user, scope and provider.
     */
    public function findConnectionFor(string $userId, string $scopeKey, CalendarProvider $provider): ?CalendarConnection
    {
        /** @var CalendarConnection|null $connection */
        $connection = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(CalendarConnection::class, 'c')
            ->where('IDENTITY(c.user) = :user')
            ->andWhere('c.scopeKey = :scope')
            ->andWhere('c.provider = :provider')
            ->setParameter('user', $userId)
            ->setParameter('scope', $scopeKey)
            ->setParameter('provider', $provider->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $connection;
    }

    /**
     * Connections of a user (one scope or all), oldest first.
     */
    public function findConnectionsByUser(string $userId, ?string $scopeKey = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(CalendarConnection::class, 'c')
            ->where('IDENTITY(c.user) = :user')
            ->setParameter('user', $userId)
            ->orderBy('c.createdAt', SortDirection::Ascending->value)
            ->addOrderBy('c.id', SortDirection::Ascending->value);
        if ($scopeKey !== null) {
            $query->andWhere('c.scopeKey = :scope')->setParameter('scope', $scopeKey);
        }

        /** @var list<CalendarConnection> $result */
        $result = $query->getQuery()->getResult();

        return $result;
    }

    /**
     * Server-mode connections due for a sync, least recently attempted first (never attempted first of all).
     */
    public function findConnectionsDue(DateTimeImmutable $now, ?string $userId, int $limit, int $offset = 0, ?array $providers = null): array
    {
        if ($providers === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->addSelect('CASE WHEN c.lastAttemptAt IS NULL THEN 0 ELSE 1 END AS HIDDEN attempted')
            ->from(CalendarConnection::class, 'c')
            ->where('c.mode = :server')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('c.nextAttemptAt IS NULL OR c.nextAttemptAt <= :now')
            ->setParameter('server', CalendarConnectionMode::Server->value)
            ->setParameter('statuses', [CalendarConnectionStatus::Active->value, CalendarConnectionStatus::Error->value])
            ->setParameter('now', StorageTime::normalize($now))
            ->orderBy('attempted', SortDirection::Ascending->value)
            ->addOrderBy('c.lastAttemptAt', SortDirection::Ascending->value)
            ->addOrderBy('c.id', SortDirection::Ascending->value)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));
        if ($userId !== null) {
            $query->andWhere('IDENTITY(c.user) = :user')->setParameter('user', $userId);
        }
        if ($providers !== null) {
            $query->andWhere('c.provider IN (:providers)')->setParameter('providers', $providers);
        }

        /** @var list<CalendarConnection> $result */
        $result = $query->getQuery()->getResult();

        return $result;
    }

    /**
     * Deletes the connection, its suggestions and the owner's rules in its scope, in one transaction.
     */
    public function deleteConnection(CalendarConnection $connection): void
    {
        try {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->entityManager->wrapInTransaction(function () use ($connection): void {
                // ORM removals (not DQL deletes), so the identity map of a worker never keeps a deleted row.
                $suggestions = $this->entityManager->getRepository(CalendarSuggestion::class)->findBy(['connection' => $connection]);
                $rules       = $this->entityManager->getRepository(CalendarRule::class)->findBy(['user' => $connection->getUser(), 'scopeKey' => $connection->getScopeKey()]);
                foreach ([...$suggestions, ...$rules, $connection] as $row) {
                    $this->entityManager->remove($row);
                }
            });
        } catch (Throwable $exception) {
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            throw $exception;
        }
    }

    /**
     * The suggestion of a connection and event reference.
     */
    public function findSuggestionByRef(string $connectionId, string $eventRef): ?CalendarSuggestion
    {
        /** @var CalendarSuggestion|null $suggestion */
        $suggestion = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(CalendarSuggestion::class, 's')
            ->where('s.connection = :connection')
            ->andWhere('s.eventRef = :ref')
            ->setParameter('connection', $connectionId)
            ->setParameter('ref', $eventRef)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $suggestion;
    }

    /**
     * Schedules a new suggestion for the next flush.
     */
    public function addSuggestion(CalendarSuggestion $suggestion): void
    {
        $this->entityManager->persist($suggestion);
    }

    /**
     * Writes the pending changes.
     */
    public function flushSuggestions(): void
    {
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Suggestions of a user (one scope or all) in the statuses, starting within `[from, to)`, oldest first.
     */
    public function findSuggestionsByUser(string $userId, ?string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to, array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('s', 'c')
            ->from(CalendarSuggestion::class, 's')
            ->innerJoin('s.connection', 'c')
            ->where('IDENTITY(s.user) = :user')
            ->andWhere('s.startsAt >= :from')
            ->andWhere('s.startsAt < :to')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $userId)
            ->setParameter('from', StorageTime::normalize($from))
            ->setParameter('to', StorageTime::normalize($to))
            ->setParameter('statuses', array_map(static fn ($status): string => $status->value, $statuses))
            ->orderBy('s.startsAt', SortDirection::Ascending->value)
            ->addOrderBy('s.id', SortDirection::Ascending->value);
        if ($scopeKey !== null) {
            $query->andWhere('c.scopeKey = :scope')->setParameter('scope', $scopeKey);
        }

        /** @var list<CalendarSuggestion> $result */
        $result = $query->getQuery()->getResult();

        return $result;
    }

    /**
     * Suggestions of the user with these ids, oldest first.
     */
    public function findSuggestionsByIds(string $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<CalendarSuggestion> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(CalendarSuggestion::class, 's')
            ->where('IDENTITY(s.user) = :user')
            ->andWhere('s.id IN (:ids)')
            ->setParameter('user', $userId)
            ->setParameter('ids', $ids)
            ->orderBy('s.startsAt', SortDirection::Ascending->value)
            ->addOrderBy('s.id', SortDirection::Ascending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Suggestions of the user in a group, oldest first.
     */
    public function findSuggestionsByGroup(string $userId, string $groupKey): array
    {
        /** @var list<CalendarSuggestion> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(CalendarSuggestion::class, 's')
            ->where('IDENTITY(s.user) = :user')
            ->andWhere('s.groupKey = :group')
            ->setParameter('user', $userId)
            ->setParameter('group', $groupKey)
            ->orderBy('s.startsAt', SortDirection::Ascending->value)
            ->addOrderBy('s.id', SortDirection::Ascending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Deletes the expired suggestions (of one connection, or all); returns the count.
     */
    public function deleteExpiredSuggestions(DateTimeImmutable $now, ?string $connectionId = null): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->delete(CalendarSuggestion::class, 's')
            ->where('s.expiresAt < :now')
            ->setParameter('now', StorageTime::normalize($now));
        if ($connectionId !== null) {
            $query->andWhere('s.connection = :connection')->setParameter('connection', $connectionId);
        }
        /** @var int $deleted */
        $deleted = $query->getQuery()->execute();

        return $deleted;
    }

    /**
     * Deletes the pending suggestions of a connection in the window that the sync did not see again.
     */
    public function deleteUnseenPending(string $connectionId, DateTimeImmutable $from, DateTimeImmutable $to, array $seenRefs): int
    {
        /** @var list<CalendarSuggestion> $pending */
        $pending = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(CalendarSuggestion::class, 's')
            ->where('s.connection = :connection')
            ->andWhere('s.status = :pending')
            ->andWhere('s.startsAt >= :from')
            ->andWhere('s.startsAt <= :to')
            ->setParameter('connection', $connectionId)
            ->setParameter('pending', CalendarSuggestionStatus::Pending->value)
            ->setParameter('from', StorageTime::normalize($from))
            ->setParameter('to', StorageTime::normalize($to))
            ->getQuery()
            ->getResult();
        $seen   = array_fill_keys($seenRefs, true);
        $unseen = [];
        foreach ($pending as $suggestion) {
            if (!isset($seen[$suggestion->getEventRef()])) {
                $unseen[] = $suggestion;
            }
        }
        if ($unseen === []) {
            return 0;
        }
        // GG-D-5: the status is in the DELETE predicate, so a row a concurrent accept decided meanwhile is kept.
        /** @var int $deleted */
        $deleted = $this->entityManager->createQueryBuilder()
            ->delete(CalendarSuggestion::class, 's')
            ->where('s.id IN (:ids)')
            ->andWhere('s.status = :pending')
            ->setParameter('ids', array_map(static fn (CalendarSuggestion $s): string => $s->getId(), $unseen))
            ->setParameter('pending', CalendarSuggestionStatus::Pending->value)
            ->getQuery()
            ->execute();
        // The DQL delete bypasses the identity map: forget the rows it may have removed.
        foreach ($unseen as $suggestion) {
            $this->entityManager->detach($suggestion);
        }

        return $deleted;
    }

    /**
     * Whether the suggestion row is still pending (a fresh read, not the identity map).
     */
    public function isStillPending(CalendarSuggestion $suggestion): bool
    {
        $status = $this->entityManager->createQueryBuilder()
            ->select('s.status')
            ->from(CalendarSuggestion::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $suggestion->getId())
            ->getQuery()
            ->getOneOrNullResult();

        $value = is_array($status) ? ($status['status'] ?? null) : null;

        return ($value instanceof CalendarSuggestionStatus ? $value : CalendarSuggestionStatus::tryFrom(is_string($value) ? $value : '')) === CalendarSuggestionStatus::Pending;
    }

    /**
     * Persists and flushes a rule.
     */
    public function saveRule(CalendarRule $rule): void
    {
        $this->entityManager->persist($rule);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * Removes a rule.
     */
    public function removeRule(CalendarRule $rule): void
    {
        $this->entityManager->remove($rule);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    /**
     * The rule with this id.
     */
    public function findRule(string $id): ?CalendarRule
    {
        return $this->entityManager->find(CalendarRule::class, $id);
    }

    /**
     * Rules of a user in a scope, oldest first.
     */
    public function findRulesByUser(string $userId, string $scopeKey): array
    {
        /** @var list<CalendarRule> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CalendarRule::class, 'r')
            ->where('IDENTITY(r.user) = :user')
            ->andWhere('r.scopeKey = :scope')
            ->setParameter('user', $userId)
            ->setParameter('scope', $scopeKey)
            ->orderBy('r.createdAt', SortDirection::Ascending->value)
            ->addOrderBy('r.id', SortDirection::Ascending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
