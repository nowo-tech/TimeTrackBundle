<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use InvalidArgumentException;

/**
 * Since 1.35.0 (DDD-host checklist B7): the read port of the time entries, so a host lists and counts them without
 * writing DQL over the bundle's entities. Returns {@see TimeEntryRecord} views, never entities; nothing it reads enters an
 * entity manager. Public service (`Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface`).
 *
 * It applies **no access rule**: the host decides who may read what (its own permission port, or the bundle's checks on
 * the client API) and passes the owner ids and scope keys that viewer may see. Every string filter compares byte for
 * byte, also on a case-insensitive MySQL collation (the rows are checked again in PHP before a page is cut).
 *
 * Stateless (FrankenPHP worker mode, kernel reset false).
 */
interface TimeEntryQueryInterface
{
    /** The largest page {@see self::list()} and {@see self::listAfter()} return. */
    public const MAX_LIMIT = 1000;

    /**
     * One entry by its exact id, or null (unknown, or an entry with no owner).
     */
    public function find(string $entryId): ?TimeEntryRecord;

    /**
     * One page of the matching entries.
     *
     * @param int $limit Entries per page (1 to {@see self::MAX_LIMIT})
     * @param int $offset Matching entries skipped (deep pages read every row before them: prefer {@see self::listAfter()})
     *
     * @throws InvalidArgumentException When the limit or the offset is out of range
     *
     * @return list<TimeEntryRecord>
     */
    public function list(TimeEntryCriteria $criteria, TimeEntrySort $sort = TimeEntrySort::StartedAtDescending, int $limit = 100, int $offset = 0): array;

    /**
     * The matching entries after an entry id, by id (keyset paging for exports and batch jobs: pass the last id of the
     * previous page, `''` for the first).
     *
     * @param int $limit Entries per page (1 to {@see self::MAX_LIMIT})
     *
     * @throws InvalidArgumentException When the limit is out of range
     *
     * @return list<TimeEntryRecord>
     */
    public function listAfter(TimeEntryCriteria $criteria, string $afterEntryId = '', int $limit = 500): array;

    /**
     * How many entries match.
     */
    public function count(TimeEntryCriteria $criteria): int;

    /**
     * The seconds of the matching entries, summed.
     */
    public function totalSeconds(TimeEntryCriteria $criteria): int;
}
