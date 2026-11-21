<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Since 1.35.0 (DDD-host checklist B7): the read port of the running timers (one per user), for host jobs such as a
 * forgotten-timer stop or an overlap check. Returns {@see RunningTimerView}s, never entities. No access rule (the host
 * decides). Public service. Stateless (FrankenPHP worker mode, kernel reset false).
 */
interface RunningTimerQueryInterface
{
    /** The largest page {@see self::startedBefore()} returns. */
    public const MAX_LIMIT = 1000;

    /**
     * The user's running timer, or null.
     *
     * @param string $ownerId The user id (as `TimeTrackUserRef::$id`), compared byte for byte
     */
    public function runningOf(string $ownerId): ?RunningTimerView;

    /**
     * The timers started before a time, by timer id (keyset paging: pass the last timer id of the previous page, `''` for
     * the first).
     *
     * @param int $limit Timers per page (1 to {@see self::MAX_LIMIT})
     *
     * @throws InvalidArgumentException When the limit is out of range
     *
     * @return list<RunningTimerView>
     */
    public function startedBefore(DateTimeImmutable $before, string $afterTimerId = '', int $limit = 200): array;
}
