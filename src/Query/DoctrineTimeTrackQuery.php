<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Doctrine\OwnerIdColumn;
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
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Timer\TimerChannel;
use Nowo\TimeTrackBundle\Timer\TimerSnapshot;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Psr\Clock\ClockInterface;
use SortDirection;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function min;
use function preg_match;
use function strcmp;
use function strlen;
use function strrpos;
use function substr;
use function usort;

/**
 * Since 1.35.0 (DDD-host checklist B7): the Doctrine implementation of {@see TimeEntryQueryInterface} (and since 2.1.0
 * {@see TimeEntryTotalsQueryInterface}),
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
final readonly class DoctrineTimeTrackQuery implements TimeEntryQueryInterface, TimeEntryTotalsQueryInterface, RunningTimerQueryInterface, ClientAccessQueryInterface, ConnectedClientDevicesQueryInterface
{
    /** Rows read per round while the rows are checked in PHP. */
    public const BATCH = 500;

    private const ENTRY_FIELDS = ['e.id AS id', 'IDENTITY(e.user) AS userId', 'e.taskId AS taskId', 'e.taskTitleSnapshot AS taskTitle', 'e.boardIdSnapshot AS boardId', 'e.scopeKey AS scopeKey', 'e.startedAt AS startedAt', 'e.endedAt AS endedAt', 'e.durationSeconds AS seconds', 'e.source AS source', 'e.externalSource AS externalSource', 'e.externalId AS externalId', 'e.channel AS channel', 'e.serviceAccount AS serviceAccount', 'e.description AS description', 'e.billable AS billable', 'e.metadata AS metadata', 'e.createdAt AS createdAt', 'e.subjectType AS subjectType', 'e.subjectId AS subjectId', 'e.activityTypeKey AS activityTypeKey'];

    private const KEY_FIELDS = ['e.id AS id', 'IDENTITY(e.user) AS userId', 'e.taskId AS taskId', 'e.boardIdSnapshot AS boardId', 'e.scopeKey AS scopeKey', 'e.durationSeconds AS seconds', 'e.subjectType AS subjectType', 'e.subjectId AS subjectId', 'e.activityTypeKey AS activityTypeKey'];

    private const TIMER_FIELDS = ['t.id AS id', 'IDENTITY(t.user) AS userId', 't.taskId AS taskId', 't.taskTitleSnapshot AS taskTitle', 't.boardIdSnapshot AS boardId', 't.startedAt AS startedAt', 't.lastHeartbeatAt AS lastHeartbeatAt', 't.clientType AS clientType', 't.metadata AS metadata', 't.description AS description', 't.scopeKey AS scopeKey', 't.subjectType AS subjectType', 't.subjectId AS subjectId', 't.activityTypeKey AS activityTypeKey'];

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
                return $this->record($row);
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function list(TimeEntryCriteria $criteria, TimeEntrySort $sort = TimeEntrySort::StartedAtDescending, int $limit = 100, int $offset = 0): array
    {
        $this->assertLimit($limit, self::MAX_LIMIT);
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
            $rows = $this->sortedEntryQuery($criteria, $sort)->setFirstResult($read)->setMaxResults($batch)->getQuery()->getArrayResult();
            $read += $batch;
            foreach ($rows as $row) {
                // Only a case-folding collation (MySQL / MariaDB suites) returns a look-alike row here.
                if (!$this->matches($criteria, $row)) {
                    continue; // @codeCoverageIgnore
                }
                if ($skipped < $offset) {
                    ++$skipped;
                    continue;
                }
                $kept[] = $this->record($row);
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
        $this->assertLimit($limit, self::MAX_LIMIT);
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
                $afterEntryId = $this->string($row['id']);
                // Only a case-folding collation (MySQL / MariaDB suites) returns a look-alike row here.
                if (!$this->matches($criteria, $row)) {
                    continue; // @codeCoverageIgnore
                }
                $kept[] = $this->record($row);
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
    public function totals(TimeEntryCriteria $criteria, TimeEntryGrouping $groupBy, int $limit = 200): TimeEntryTotals
    {
        $this->assertLimit($limit, self::MAX_GROUPS);
        if (!$criteria->startedFrom instanceof DateTimeImmutable || !$criteria->startedTo instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('TimeTrack entry totals need a closed start range (startedFrom and startedTo).');
        }
        if ($criteria->startedTo < $criteria->startedFrom || $criteria->startedTo->getTimestamp() - $criteria->startedFrom->getTimestamp() > self::MAX_RANGE_DAYS * 86_400) {
            throw new InvalidArgumentException('TimeTrack entry totals take a start range of at most ' . self::MAX_RANGE_DAYS . ' days.');
        }
        if ($criteria->matchesNothing()) {
            return new TimeEntryTotals([]);
        }

        $columns = match ($groupBy) {
            TimeEntryGrouping::Subject                => ['e.subjectType AS gSubjectType', 'e.subjectId AS gSubjectId'],
            TimeEntryGrouping::SubjectType            => ['e.subjectType AS gSubjectType'],
            TimeEntryGrouping::ActivityType           => ['e.activityTypeKey AS gActivity'],
            TimeEntryGrouping::SubjectAndActivityType => ['e.subjectType AS gSubjectType', 'e.subjectId AS gSubjectId', 'e.activityTypeKey AS gActivity'],
            TimeEntryGrouping::Owner                  => ['IDENTITY(e.user) AS gOwner'],
            TimeEntryGrouping::Task                   => ['e.taskId AS gTask'],
            TimeEntryGrouping::Allocation             => ["CASE WHEN e.taskId IS NOT NULL AND e.subjectType IS NOT NULL THEN 'task_subject' WHEN e.taskId IS NOT NULL THEN 'task' WHEN e.subjectType IS NOT NULL THEN 'subject' ELSE 'none' END AS gAllocation"],
        };
        // Audit ZBQ-L2: the key columns the criteria filter by are grouped too and checked again byte for byte in PHP, as
        // the list port does (a host column left on a folding collation would otherwise add `acme`'s time to `ACME`'s);
        // the groups are then merged, sorted and cut here. Still one statement, read as a stream.
        $keys = array_filter([
            'kScope' => $criteria->scopeKeys !== null ? 'e.scopeKey AS kScope' : null,
            'kBoard' => $criteria->boardIds !== null ? 'e.boardIdSnapshot AS kBoard' : null,
            'kOwner' => $criteria->ownerIds !== null ? 'IDENTITY(e.user) AS kOwner' : null,
            'kTask'  => $criteria->taskIds !== null ? 'e.taskId AS kTask' : null,
        ]);
        $query = $this->entryQuery($criteria)->select(...[...$columns, ...array_values($keys), 'SUM(e.durationSeconds) AS seconds', 'COUNT(e.id) AS entries']);
        foreach ([...$columns, ...array_values($keys)] as $column) {
            // @igor-ignore - A query builder built for this call (entryQuery()), not a shared service.
            $query->addGroupBy(substr($column, (int) strrpos($column, ' AS ') + 4));
        }

        $merged = [];
        // @igor-ignore - A query builder built for this call (entryQuery()), not a shared service.
        foreach ($query->getQuery()->toIterable([], AbstractQuery::HYDRATE_ARRAY) as $row) {
            /** @var array<string, mixed> $row */
            $key = $this->groupKey($groupBy, $row);
            // Only a case-folding collation (MySQL / MariaDB suites) returns a look-alike row here.
            if (!$this->groupMatches($criteria, $key) || !$this->keysMatch($criteria, $row)) {
                continue; // @codeCoverageIgnore
            }
            $id          = $key->key();
            $merged[$id] = new TimeEntryTotal($key, ($merged[$id]->seconds ?? 0) + $this->int($row['seconds']), ($merged[$id]->entries ?? 0) + $this->int($row['entries']));
        }
        $totals = array_values($merged);
        usort($totals, static fn (TimeEntryTotal $a, TimeEntryTotal $b): int => $b->seconds <=> $a->seconds ?: strcmp($a->key->key(), $b->key->key()));

        return new TimeEntryTotals(array_slice($totals, 0, $limit), count($totals) > $limit);
    }

    /**
     * Audit ZBQ-L2: whether the key columns of a totals row match the criteria byte for byte.
     *
     * @param array<string, mixed> $row
     */
    private function keysMatch(TimeEntryCriteria $criteria, array $row): bool
    {
        return ($criteria->scopeKeys === null || ScopeKeys::isOneOf($this->nullableString($row['kScope'] ?? null), $criteria->scopeKeys))
            && ($criteria->boardIds === null || in_array($this->nullableString($row['kBoard'] ?? null), $criteria->boardIds, true))
            && ($criteria->ownerIds === null || in_array($this->owner(['userId' => $row['kOwner'] ?? null]), $criteria->ownerIds, true))
            && ($criteria->taskIds === null || in_array($this->nullableString($row['kTask'] ?? null), $criteria->taskIds, true));
    }

    /**
     * The group key of a totals row.
     *
     * @param array<array-key, mixed> $row
     */
    private function groupKey(TimeEntryGrouping $groupBy, array $row): TimeEntryGroupKey
    {
        return match ($groupBy) {
            TimeEntryGrouping::Subject                => new TimeEntryGroupKey(subject: TimeSubjectRef::tryFromColumns($row['gSubjectType'], $row['gSubjectId'])),
            TimeEntryGrouping::SubjectType            => new TimeEntryGroupKey(subjectType: $this->nullableString($row['gSubjectType'])),
            TimeEntryGrouping::ActivityType           => new TimeEntryGroupKey(activityTypeKey: $this->nullableString($row['gActivity'])),
            TimeEntryGrouping::SubjectAndActivityType => new TimeEntryGroupKey(subject: TimeSubjectRef::tryFromColumns($row['gSubjectType'], $row['gSubjectId']), activityTypeKey: $this->nullableString($row['gActivity'])),
            TimeEntryGrouping::Owner                  => new TimeEntryGroupKey(ownerId: $this->owner(['userId' => $row['gOwner']])),
            TimeEntryGrouping::Task                   => new TimeEntryGroupKey(taskId: $this->nullableString($row['gTask'])),
            TimeEntryGrouping::Allocation             => new TimeEntryGroupKey(allocation: AllocationFilter::from($this->string($row['gAllocation']))),
        };
    }

    /**
     * Whether a group's key matches the filters on its own columns byte for byte.
     */
    private function groupMatches(TimeEntryCriteria $criteria, TimeEntryGroupKey $key): bool
    {
        $subjectType = $key->subject->type ?? $key->subjectType;

        return ($key->ownerId === null || $criteria->ownerIds === null || in_array($key->ownerId, $criteria->ownerIds, true))
            && ($key->taskId === null || $criteria->taskIds === null || in_array($key->taskId, $criteria->taskIds, true))
            && ($subjectType === null || $criteria->subjectTypes === null || in_array($subjectType, $criteria->subjectTypes, true))
            && ($key->activityTypeKey === null || $criteria->activityTypeKeys === null || in_array($key->activityTypeKey, $criteria->activityTypeKeys, true))
            && (!$key->subject instanceof TimeSubjectRef || $criteria->subjects === null || $criteria->hasSubject($key->subject));
    }

    /**
     * {@inheritDoc}
     */
    public function runningOf(string $ownerId): ?RunningTimerView
    {
        if (!$this->isOwnerId(ActiveTimer::class, $ownerId)) {
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
            if ($this->owner($row) === $ownerId) {
                return $this->timer($row);
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function startedBefore(DateTimeImmutable $before, string $afterTimerId = '', int $limit = 200): array
    {
        $this->assertLimit($limit, self::MAX_TIMER_LIMIT);
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

        return array_values(array_map($this->timer(...), $rows));
    }

    /**
     * {@inheritDoc}
     */
    public function sessionsOf(string $ownerId, bool $includeExpired = false): array
    {
        if (!$this->isOwnerId(ClientToken::class, $ownerId)) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('c.id AS id', 'IDENTITY(c.user) AS userId', 'c.clientType AS clientType', 'c.credentialId AS credentialId', 'c.scopes AS scopes', 'c.createdAt AS createdAt', 'c.lastUsedAt AS lastUsedAt', 'c.expiresAt AS expiresAt', 'c.scopeFamily AS scopeFamily', 'c.deviceKey AS deviceKey', 'c.clientName AS clientName')
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
            // Only a case-folding collation (MySQL / MariaDB suites) returns a look-alike row here.
            if ($this->owner($row) !== $ownerId) {
                continue; // @codeCoverageIgnore
            }
            $family  = $this->string($row['scopeFamily']);
            $views[] = new ClientSessionView(
                $this->string($row['id']),
                $ownerId,
                $this->clientType($row['clientType']),
                $this->nullableString($row['credentialId']),
                $this->scopeValues($family, $row['scopes'], true),
                $this->date($row['createdAt']),
                $this->nullableDate($row['lastUsedAt']),
                $this->date($row['expiresAt']),
                $family,
                $this->nullableString($row['deviceKey']),
                $this->nullableString($row['clientName']),
            );
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function deviceSignInsOf(string $ownerId): array
    {
        if (!$this->isOwnerId(DeviceAuthorization::class, $ownerId)) {
            return [];
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.id AS id', 'IDENTITY(d.user) AS userId', 'd.clientType AS clientType', 'd.clientName AS clientName', 'd.status AS status', 'd.scopes AS scopes', 'd.ipPrefix AS ipPrefix', 'd.createdAt AS createdAt', 'd.expiresAt AS expiresAt', 'd.lastPolledAt AS lastPolledAt', 'd.scopeFamily AS scopeFamily')
            ->from(DeviceAuthorization::class, 'd')
            ->where('IDENTITY(d.user) = :owner')
            ->setParameter('owner', $ownerId)
            ->orderBy('d.createdAt', SortDirection::Descending)
            ->addOrderBy('d.id', SortDirection::Descending)
            ->getQuery()
            ->getArrayResult();

        $views = [];
        foreach ($rows as $row) {
            // Only a case-folding collation (MySQL / MariaDB suites) returns a look-alike row here.
            if ($this->owner($row) !== $ownerId) {
                continue; // @codeCoverageIgnore
            }
            $status  = $row['status'];
            $family  = $this->string($row['scopeFamily']);
            $views[] = new DeviceSignInView(
                $this->string($row['id']),
                $ownerId,
                $this->clientType($row['clientType']),
                $this->string($row['clientName']),
                $status instanceof DeviceAuthorizationStatus ? $status : DeviceAuthorizationStatus::from($this->string($status)),
                $this->scopeValues($family, $row['scopes'], false),
                $this->nullableString($row['ipPrefix']),
                $this->date($row['createdAt']),
                $this->date($row['expiresAt']),
                $this->nullableDate($row['lastPolledAt']),
                $family,
            );
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     *
     * Since 2.2.0: built from {@see self::sessionsOf()} (unexpired sessions) in PHP; a session without a device key is a
     * device of its own.
     */
    public function devicesOf(string $ownerId): array
    {
        /** @var array<string, list<ClientSessionView>> $groups */
        $groups = [];
        foreach ($this->sessionsOf($ownerId) as $session) {
            $groups[$session->deviceKey ?? $session->id][] = $session;
        }

        $devices = [];
        foreach ($groups as $deviceKey => $sessions) {
            $families    = [];
            $clientName  = null;
            $connectedAt = $sessions[0]->createdAt;
            $lastUsedAt  = null;
            foreach ($sessions as $session) {
                $families[$session->scopeFamily] = true;
                $clientName ??= $session->clientName;
                $connectedAt = $session->createdAt < $connectedAt ? $session->createdAt : $connectedAt;
                if ($session->lastUsedAt !== null && ($lastUsedAt === null || $session->lastUsedAt > $lastUsedAt)) {
                    $lastUsedAt = $session->lastUsedAt;
                }
            }
            $keys = array_keys($families);
            usort($keys, static fn (string $a, string $b): int => [$a !== DeviceScopeFamily::TIME, $a] <=> [$b !== DeviceScopeFamily::TIME, $b]);
            $devices[] = new ConnectedDeviceView((string) $deviceKey, $sessions[0]->clientType, $clientName, $keys, $sessions, $connectedAt, $lastUsedAt);
        }
        usort($devices, static fn (ConnectedDeviceView $a, ConnectedDeviceView $b): int => [$b->lastUsedAt ?? $b->connectedAt, $a->deviceKey] <=> [$a->lastUsedAt ?? $a->connectedAt, $b->deviceKey]);

        return $devices;
    }

    /**
     * Since 2.2.0: the scope values of a stored row of a family (`time`: the known time scopes; another family: its
     * well-formed values).
     *
     * @param bool $nullMeansDefaults Whether a null list means the default time scopes (a token issued before 1.9)
     *
     * @return list<string>
     */
    private function scopeValues(string $family, mixed $scopes, bool $nullMeansDefaults): array
    {
        if ($family === DeviceScopeFamily::TIME) {
            $known = is_array($scopes) ? ClientScope::normalize($scopes) : ($nullMeansDefaults ? ClientScope::defaults() : []);

            return array_map(static fn (ClientScope $scope): string => $scope->value, $known);
        }
        $values = [];
        foreach (is_array($scopes) ? $scopes : [] as $scope) {
            if (is_string($scope) && preg_match(DeviceScopeFamily::SCOPE_PATTERN, $scope) === 1) {
                $values[] = $scope;
            }
        }

        return $values;
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
                $after = $this->string($row['id']);
                if ($this->matches($criteria, $row)) {
                    ++$count;
                    $seconds += $this->int($row['seconds']);
                }
            }
        } while (count($rows) === self::BATCH);

        return [$count, $seconds];
    }

    /**
     * A new query of the entry fields in the requested order.
     */
    private function sortedEntryQuery(TimeEntryCriteria $criteria, TimeEntrySort $sort): QueryBuilder
    {
        return match ($sort) {
            TimeEntrySort::StartedAtDescending => $this->entryQuery($criteria)->select(...self::ENTRY_FIELDS)->orderBy('e.startedAt', SortDirection::Descending)->addOrderBy('e.id', SortDirection::Descending),
            TimeEntrySort::StartedAtAscending  => $this->entryQuery($criteria)->select(...self::ENTRY_FIELDS)->orderBy('e.startedAt', SortDirection::Ascending)->addOrderBy('e.id', SortDirection::Ascending),
            TimeEntrySort::IdAscending         => $this->entryQuery($criteria)->select(...self::ENTRY_FIELDS)->orderBy('e.id', SortDirection::Ascending),
        };
    }

    /**
     * A new query of the owned entries that match the criteria in SQL (the rows are checked again in PHP).
     */
    private function entryQuery(TimeEntryCriteria $criteria): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()->from(TimeEntry::class, 'e');
        if ($criteria->ownerIds !== null) {
            // An id the owner column cannot hold owns nothing (and never reaches the SQL: PostgreSQL rejects it).
            $owners = array_values(array_filter($criteria->ownerIds, fn (string $id): bool => OwnerIdColumn::accepts($this->entityManager, TimeEntry::class, $id)));
            if ($owners === []) {
                $query->andWhere('e.id IS NULL');
            } else {
                $query->andWhere('IDENTITY(e.user) IN (:owners)')->setParameter('owners', $owners);
            }
        } else {
            $query->andWhere('e.user IS NOT NULL');
        }
        foreach (['scopeKey' => $criteria->scopeKeys, 'taskId' => $criteria->taskIds, 'boardIdSnapshot' => $criteria->boardIds] as $field => $values) {
            if ($values !== null) {
                $query->andWhere('e.' . $field . ' IN (:' . $field . 's)')->setParameter($field . 's', $values);
            }
        }
        if ($criteria->startedFrom instanceof DateTimeImmutable) {
            $query->andWhere('e.startedAt >= :startedFrom')->setParameter('startedFrom', $criteria->startedFrom);
        }
        if ($criteria->startedTo instanceof DateTimeImmutable) {
            $query->andWhere('e.startedAt <= :startedTo')->setParameter('startedTo', $criteria->startedTo);
        }
        if ($criteria->overlapFrom instanceof DateTimeImmutable && $criteria->overlapTo instanceof DateTimeImmutable) {
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

        return $this->allocationFilters($query, $criteria);
    }

    /**
     * Since 2.1.0 (Beacon spec 256): the subject, subject type, activity type and allocation filters (the rows are checked
     * again in PHP). A subject filter is written per type: `subject_type = ? AND subject_id IN (…)`, OR-ed.
     */
    private function allocationFilters(QueryBuilder $query, TimeEntryCriteria $criteria): QueryBuilder
    {
        $byType = $criteria->subjectIdsByType();
        if ($byType !== null) {
            $or = [];
            $i  = 0;
            foreach ($byType as $type => $ids) {
                $or[] = '(e.subjectType = :subjectType' . $i . ' AND e.subjectId IN (:subjectIds' . $i . '))';
                $query->setParameter('subjectType' . $i, (string) $type)->setParameter('subjectIds' . $i, $ids);
                ++$i;
            }
            $query->andWhere(implode(' OR ', $or));
        }
        if ($criteria->subjectTypes !== null) {
            $query->andWhere('e.subjectType IN (:subjectTypes)')->setParameter('subjectTypes', $criteria->subjectTypes);
        }
        if ($criteria->activityTypeKeys !== null) {
            $query->andWhere('e.activityTypeKey IN (:activityTypeKeys)')->setParameter('activityTypeKeys', $criteria->activityTypeKeys);
        }

        return match ($criteria->allocation) {
            AllocationFilter::Any            => $query,
            AllocationFilter::TaskOnly       => $query->andWhere('e.taskId IS NOT NULL')->andWhere('e.subjectType IS NULL'),
            AllocationFilter::TaskAndSubject => $query->andWhere('e.taskId IS NOT NULL')->andWhere('e.subjectType IS NOT NULL'),
            AllocationFilter::SubjectOnly    => $query->andWhere('e.taskId IS NULL')->andWhere('e.subjectType IS NOT NULL'),
            AllocationFilter::Unallocated    => $query->andWhere('e.taskId IS NULL')->andWhere('e.subjectType IS NULL'),
        };
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function matches(TimeEntryCriteria $criteria, array $row): bool
    {
        return $criteria->matchesExactly($this->string($row['id']), $this->owner($row), $this->nullableString($row['scopeKey']), $this->string($row['taskId']), $this->nullableString($row['boardId']), TimeSubjectRef::tryFromColumns($row['subjectType'], $row['subjectId']), $this->nullableString($row['activityTypeKey']));
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function record(array $row): TimeEntryRecord
    {
        $metadata = is_array($row['metadata']) ? $row['metadata'] : [];
        $note     = $metadata['note'] ?? null;
        $source   = $row['source'];
        $channel  = $this->nullableString($row['channel']);

        /* @var array<string, mixed> $metadata */
        return new TimeEntryRecord(
            new TimeEntryView(
                $this->string($row['id']),
                $this->owner($row),
                $this->string($row['taskId']),
                $this->string($row['taskTitle']),
                $this->nullableString($row['boardId']),
                $this->nullableString($row['scopeKey']),
                $this->date($row['startedAt']),
                $this->date($row['endedAt']),
                $this->int($row['seconds']),
                $source instanceof TimeEntrySource ? $source : TimeEntrySource::from($this->string($source)),
                $this->nullableString($row['externalSource']),
                $this->nullableString($row['externalId']),
                $channel !== null && preg_match(TimerChannel::PATTERN, $channel) === 1 ? $channel : null,
                $this->bool($row['serviceAccount']),
                // Since 1.36.0: a NULL task id is an entry without a task.
                $row['taskId'] === null,
                // Since 2.1.0: the subject and activity type (a pair that breaks the column contract reads as none).
                TimeSubjectRef::tryFromColumns($row['subjectType'], $row['subjectId']),
                $this->nullableString($row['activityTypeKey']),
            ),
            is_string($note) && $note !== '' ? $note : null,
            $this->nullableString($row['description']),
            $row['billable'] === null ? null : $this->bool($row['billable']),
            $metadata,
            $this->date($row['createdAt']),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function timer(array $row): RunningTimerView
    {
        $metadata = is_array($row['metadata']) ? $row['metadata'] : [];
        $channel  = $metadata[TimerService::CHANNEL_KEY] ?? null;
        $note     = $metadata['note'] ?? null;

        return new RunningTimerView(
            new TimerSnapshot(
                $this->string($row['id']),
                $this->string($row['taskId']),
                $this->string($row['taskTitle']),
                $this->nullableString($row['boardId']),
                $this->date($row['startedAt']),
                $this->clientType($row['clientType']),
                is_string($channel) && preg_match(TimerChannel::PATTERN, $channel) === 1 ? $channel : null,
                is_string($note) ? $note : null,
                // Since 1.36.0: a timer without a task, its free text and its tenant scope.
                $row['taskId'] === null,
                $this->nullableString($row['description']),
                $this->nullableString($row['scopeKey']),
                TimeSubjectRef::tryFromColumns($row['subjectType'], $row['subjectId']),
                $this->nullableString($row['activityTypeKey']),
            ),
            (string) $this->owner($row),
            $this->date($row['lastHeartbeatAt']),
        );
    }

    /**
     * @param class-string $class
     */
    private function isOwnerId(string $class, string $ownerId): bool
    {
        return $ownerId !== '' && DoctrineUserReferenceResolver::isExactId($ownerId) && OwnerIdColumn::accepts($this->entityManager, $class, $ownerId);
    }

    private function assertLimit(int $limit, int $max): void
    {
        if ($limit < 1 || $limit > $max) {
            throw new InvalidArgumentException('A TimeTrack query limit must be between 1 and ' . $max . '.');
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function owner(array $row): ?string
    {
        return is_scalar($row['userId']) ? (string) $row['userId'] : null;
    }

    private function clientType(mixed $value): ClientType
    {
        return $value instanceof ClientType ? $value : ClientType::from($this->string($value));
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function int(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    private function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : (is_scalar($value) && (bool) $value);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable($this->string($value));
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date($value);
    }
}
