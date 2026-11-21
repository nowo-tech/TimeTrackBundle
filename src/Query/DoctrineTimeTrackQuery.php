<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Timer\TimerChannel;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use Psr\Clock\ClockInterface;
use SortDirection;

use function array_map;
use function count;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function min;
use function preg_match;
use function strlen;

/**
 * Since 1.35.0 (DDD-host checklist B7): the Doctrine implementation of {@see TimeEntryQueryInterface},
 * {@see RunningTimerQueryInterface} and {@see ClientAccessQueryInterface} on the bundle's entity manager (the private one
 * with `database.private_entity_manager: true`).
 *
 * Every query selects scalars (no entity is hydrated, so nothing enters any entity manager, the host's included) and
 * takes ids as plain parameters (never a host entity). The rows are checked again in PHP byte for byte (owner ids, scope
 * keys, task and board ids) before a page is cut or a row is counted, on top of the exact collations of the identifier
 * columns (audit LQ rule). Read in batches of at most {@see self::BATCH} rows; nothing is kept between calls (FrankenPHP
 * worker mode, kernel reset false).
 *
 * @internal Hosts depend on the interfaces
 */
final readonly class DoctrineTimeTrackQuery implements TimeEntryQueryInterface, RunningTimerQueryInterface, ClientAccessQueryInterface
{
    /** Rows read per round while the rows are checked in PHP. */
    public const BATCH = 500;

    private const ENTRY_FIELDS = ['e.id AS id', 'IDENTITY(e.user) AS userId', 'e.taskId AS taskId', 'e.taskTitleSnapshot AS taskTitle', 'e.boardIdSnapshot AS boardId', 'e.scopeKey AS scopeKey', 'e.startedAt AS startedAt', 'e.endedAt AS endedAt', 'e.durationSeconds AS seconds', 'e.source AS source', 'e.externalSource AS externalSource', 'e.externalId AS externalId', 'e.channel AS channel', 'e.serviceAccount AS serviceAccount', 'e.description AS description', 'e.billable AS billable', 'e.metadata AS metadata', 'e.createdAt AS createdAt'];

    private const KEY_FIELDS = ['e.id AS id', 'IDENTITY(e.user) AS userId', 'e.taskId AS taskId', 'e.boardIdSnapshot AS boardId', 'e.scopeKey AS scopeKey', 'e.durationSeconds AS seconds'];

    private const TIMER_FIELDS = ['t.id AS id', 'IDENTITY(t.user) AS userId', 't.taskId AS taskId', 't.taskTitleSnapshot AS taskTitle', 't.boardIdSnapshot AS boardId', 't.startedAt AS startedAt', 't.lastHeartbeatAt AS lastHeartbeatAt', 't.clientType AS clientType', 't.metadata AS metadata'];

    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager (private or `database.entity_manager`)
     * @param ClockInterface|null $clock Time source of the session expiry (null = the system time)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $entryId): ?TimeEntryRecord
    {
        if ($entryId === '' || strlen($entryId) > 36) {
            return null;
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select(...self::ENTRY_FIELDS)
            ->from(TimeEntry::class, 'e')
            ->where('e.id = :id')
            ->andWhere('e.user IS NOT NULL')
            ->setParameter('id', $entryId)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            if ($row['id'] === $entryId) {
                return self::record($row);
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function list(TimeEntryCriteria $criteria, TimeEntrySort $sort = TimeEntrySort::StartedAtDescending, int $limit = 100, int $offset = 0): array
    {
        self::assertLimit($limit, self::MAX_LIMIT);
        if ($offset < 0) {
            throw new InvalidArgumentException('A TimeTrack entry query offset cannot be negative.');
        }
        if ($criteria->matchesNothing()) {
            return [];
        }

        // The page is cut after the PHP check: a row a case-folding collation matched neither shows nor shifts the pages.
        $batch   = min(self::BATCH, $offset + $limit);
        $kept    = [];
        $skipped = 0;
        $read    = 0;
        do {
            $query = $this->entryQuery($criteria)->select(...self::ENTRY_FIELDS);
            match ($sort) {
                TimeEntrySort::StartedAtDescending => $query->orderBy('e.startedAt', SortDirection::Descending)->addOrderBy('e.id', SortDirection::Descending),
                TimeEntrySort::StartedAtAscending  => $query->orderBy('e.startedAt', SortDirection::Ascending)->addOrderBy('e.id', SortDirection::Ascending),
                TimeEntrySort::IdAscending         => $query->orderBy('e.id', SortDirection::Ascending),
            };
            $rows = $query->setFirstResult($read)->setMaxResults($batch)->getQuery()->getArrayResult();
            $read += $batch;
            foreach ($rows as $row) {
                if (!self::matches($criteria, $row)) {
                    continue;
                }
                if ($skipped < $offset) {
                    ++$skipped;
                    continue;
                }
                $kept[] = self::record($row);
                if (count($kept) === $limit) {
                    return $kept;
                }
            }
        } while (count($rows) === $batch);

        return $kept;
    }

    /**
     * {@inheritDoc}
     */
    public function listAfter(TimeEntryCriteria $criteria, string $afterEntryId = '', int $limit = 500): array
    {
        self::assertLimit($limit, self::MAX_LIMIT);
        if ($criteria->matchesNothing()) {
            return [];
        }

        $kept = [];
        do {
            $rows = $this->entryQuery($criteria)
                ->select(...self::ENTRY_FIELDS)
                ->andWhere('e.id > :afterEntry')
                ->setParameter('afterEntry', $afterEntryId)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(self::BATCH)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $afterEntryId = self::string($row['id']);
                if (!self::matches($criteria, $row)) {
                    continue;
                }
                $kept[] = self::record($row);
                if (count($kept) === $limit) {
                    return $kept;
                }
            }
        } while (count($rows) === self::BATCH);

        return $kept;
    }

    /**
     * {@inheritDoc}
     */
    public function count(TimeEntryCriteria $criteria): int
    {
        return $this->aggregate($criteria)[0];
    }

    /**
     * {@inheritDoc}
     */
    public function totalSeconds(TimeEntryCriteria $criteria): int
    {
        return $this->aggregate($criteria)[1];
    }

    /**
     * {@inheritDoc}
     */
    public function runningOf(string $ownerId): ?RunningTimerView
    {
        if (!self::isOwnerId($ownerId)) {
            return null;
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select(...self::TIMER_FIELDS)
            ->from(ActiveTimer::class, 't')
            ->where('IDENTITY(t.user) = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            if (self::owner($row) === $ownerId) {
                return self::timer($row);
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function startedBefore(DateTimeImmutable $before, string $afterTimerId = '', int $limit = 200): array
    {
        self::assertLimit($limit, self::MAX_LIMIT);
        $rows = $this->entityManager->createQueryBuilder()
            ->select(...self::TIMER_FIELDS)
            ->from(ActiveTimer::class, 't')
            ->where('t.startedAt < :before')
            ->andWhere('t.id > :afterTimer')
            ->setParameter('before', $before)
            ->setParameter('afterTimer', $afterTimerId)
            ->orderBy('t.id', SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(self::timer(...), $rows);
    }

    /**
     * {@inheritDoc}
     */
    public function sessionsOf(string $ownerId, bool $includeExpired = false): array
    {
        if (!self::isOwnerId($ownerId)) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('c.id AS id', 'IDENTITY(c.user) AS userId', 'c.clientType AS clientType', 'c.credentialId AS credentialId', 'c.scopes AS scopes', 'c.createdAt AS createdAt', 'c.lastUsedAt AS lastUsedAt', 'c.expiresAt AS expiresAt')
            ->from(ClientToken::class, 'c')
            ->where('IDENTITY(c.user) = :owner')
            ->setParameter('owner', $ownerId)
            ->orderBy('c.createdAt', SortDirection::Descending)
            ->addOrderBy('c.id', SortDirection::Descending);
        if (!$includeExpired) {
            $query->andWhere('c.expiresAt > :now')->setParameter('now', $this->clock?->now() ?? new DateTimeImmutable());
        }

        $views = [];
        foreach ($query->getQuery()->getArrayResult() as $row) {
            if (self::owner($row) !== $ownerId) {
                continue;
            }
            $scopes  = is_array($row['scopes']) ? ClientScope::normalize($row['scopes']) : ClientScope::defaults();
            $views[] = new ClientSessionView(
                self::string($row['id']),
                $ownerId,
                self::clientType($row['clientType']),
                self::nullableString($row['credentialId']),
                array_map(static fn (ClientScope $scope): string => $scope->value, $scopes),
                self::date($row['createdAt']),
                self::nullableDate($row['lastUsedAt']),
                self::date($row['expiresAt']),
            );
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function deviceSignInsOf(string $ownerId): array
    {
        if (!self::isOwnerId($ownerId)) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.id AS id', 'IDENTITY(d.user) AS userId', 'd.clientType AS clientType', 'd.clientName AS clientName', 'd.status AS status', 'd.scopes AS scopes', 'd.ipPrefix AS ipPrefix', 'd.createdAt AS createdAt', 'd.expiresAt AS expiresAt', 'd.lastPolledAt AS lastPolledAt')
            ->from(DeviceAuthorization::class, 'd')
            ->where('IDENTITY(d.user) = :owner')
            ->setParameter('owner', $ownerId)
            ->orderBy('d.createdAt', SortDirection::Descending)
            ->addOrderBy('d.id', SortDirection::Descending)
            ->getQuery()
            ->getArrayResult();

        $views = [];
        foreach ($rows as $row) {
            if (self::owner($row) !== $ownerId) {
                continue;
            }
            $status  = $row['status'];
            $views[] = new DeviceSignInView(
                self::string($row['id']),
                $ownerId,
                self::clientType($row['clientType']),
                self::string($row['clientName']),
                $status instanceof DeviceAuthorizationStatus ? $status : DeviceAuthorizationStatus::from(self::string($status)),
                array_map(static fn (ClientScope $scope): string => $scope->value, ClientScope::normalize(is_array($row['scopes']) ? $row['scopes'] : [])),
                self::nullableString($row['ipPrefix']),
                self::date($row['createdAt']),
                self::date($row['expiresAt']),
                self::nullableDate($row['lastPolledAt']),
            );
        }

        return $views;
    }

    /**
     * The entries' count and seconds, read by keyset in batches of key columns (only one batch is held in memory).
     *
     * @return array{int, int}
     */
    private function aggregate(TimeEntryCriteria $criteria): array
    {
        if ($criteria->matchesNothing()) {
            return [0, 0];
        }
        $count   = 0;
        $seconds = 0;
        $after   = '';
        do {
            $rows = $this->entryQuery($criteria)
                ->select(...self::KEY_FIELDS)
                ->andWhere('e.id > :afterEntry')
                ->setParameter('afterEntry', $after)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults(self::BATCH)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $after = self::string($row['id']);
                if (self::matches($criteria, $row)) {
                    ++$count;
                    $seconds += self::int($row['seconds']);
                }
            }
        } while (count($rows) === self::BATCH);

        return [$count, $seconds];
    }

    /**
     * A new query of the owned entries that match the criteria in SQL (the rows are checked again in PHP).
     */
    private function entryQuery(TimeEntryCriteria $criteria): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(TimeEntry::class, 'e');
        if ($criteria->ownerIds !== null) {
            $query->andWhere('IDENTITY(e.user) IN (:owners)')->setParameter('owners', $criteria->ownerIds);
        } else {
            $query->andWhere('e.user IS NOT NULL');
        }
        foreach (['scopeKey' => $criteria->scopeKeys, 'taskId' => $criteria->taskIds, 'boardIdSnapshot' => $criteria->boardIds] as $field => $values) {
            if ($values !== null) {
                $query->andWhere('e.' . $field . ' IN (:' . $field . 's)')->setParameter($field . 's', $values);
            }
        }
        if ($criteria->startedFrom !== null) {
            $query->andWhere('e.startedAt >= :startedFrom')->setParameter('startedFrom', $criteria->startedFrom);
        }
        if ($criteria->startedTo !== null) {
            $query->andWhere('e.startedAt <= :startedTo')->setParameter('startedTo', $criteria->startedTo);
        }
        if ($criteria->overlapFrom !== null && $criteria->overlapTo !== null) {
            $query->andWhere('e.endedAt > :overlapFrom')->andWhere('e.startedAt < :overlapTo')
                ->setParameter('overlapFrom', $criteria->overlapFrom)
                ->setParameter('overlapTo', $criteria->overlapTo);
        }
        if ($criteria->sources !== null) {
            $query->andWhere('e.source IN (:sources)')->setParameter('sources', array_map(static fn (TimeEntrySource $source): string => $source->value, $criteria->sources));
        }
        if ($criteria->serviceAccount !== null) {
            $query->andWhere('e.serviceAccount = :serviceAccount')->setParameter('serviceAccount', $criteria->serviceAccount);
        }
        if ($criteria->excludeEntryIds !== []) {
            $query->andWhere('e.id NOT IN (:excluded)')->setParameter('excluded', $criteria->excludeEntryIds);
        }

        return $query;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function matches(TimeEntryCriteria $criteria, array $row): bool
    {
        return $criteria->matchesExactly(self::string($row['id']), self::owner($row), self::nullableString($row['scopeKey']), self::string($row['taskId']), self::nullableString($row['boardId']));
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function record(array $row): TimeEntryRecord
    {
        $metadata = is_array($row['metadata']) ? $row['metadata'] : [];
        $note     = $metadata['note'] ?? null;
        $source   = $row['source'];
        $channel  = self::nullableString($row['channel']);

        /** @var array<string, mixed> $metadata */
        return new TimeEntryRecord(
            new TimeEntryView(
                self::string($row['id']),
                self::owner($row),
                self::string($row['taskId']),
                self::string($row['taskTitle']),
                self::nullableString($row['boardId']),
                self::nullableString($row['scopeKey']),
                self::date($row['startedAt']),
                self::date($row['endedAt']),
                self::int($row['seconds']),
                $source instanceof TimeEntrySource ? $source : TimeEntrySource::from(self::string($source)),
                self::nullableString($row['externalSource']),
                self::nullableString($row['externalId']),
                $channel !== null && preg_match(TimerChannel::PATTERN, $channel) === 1 ? $channel : null,
                self::bool($row['serviceAccount']),
            ),
            is_string($note) && $note !== '' ? $note : null,
            self::nullableString($row['description']),
            $row['billable'] === null ? null : self::bool($row['billable']),
            $metadata,
            self::date($row['createdAt']),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function timer(array $row): RunningTimerView
    {
        $metadata = is_array($row['metadata']) ? $row['metadata'] : [];
        $channel  = $metadata[TimerService::CHANNEL_KEY] ?? null;
        $note     = $metadata['note'] ?? null;

        return new RunningTimerView(
            new TimerSnapshot(
                self::string($row['id']),
                self::string($row['taskId']),
                self::string($row['taskTitle']),
                self::nullableString($row['boardId']),
                self::date($row['startedAt']),
                self::clientType($row['clientType']),
                is_string($channel) && preg_match(TimerChannel::PATTERN, $channel) === 1 ? $channel : null,
                is_string($note) ? $note : null,
            ),
            (string) self::owner($row),
            self::date($row['lastHeartbeatAt']),
        );
    }

    private static function isOwnerId(string $ownerId): bool
    {
        return $ownerId !== '' && DoctrineUserReferenceResolver::isExactId($ownerId);
    }

    private static function assertLimit(int $limit, int $max): void
    {
        if ($limit < 1 || $limit > $max) {
            throw new InvalidArgumentException('A TimeTrack query limit must be between 1 and ' . $max . '.');
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function owner(array $row): ?string
    {
        return is_scalar($row['userId']) ? (string) $row['userId'] : null;
    }

    private static function clientType(mixed $value): ClientType
    {
        return $value instanceof ClientType ? $value : ClientType::from(self::string($value));
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    private static function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : (is_scalar($value) && (bool) $value);
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable(self::string($value));
    }

    private static function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::date($value);
    }
}
