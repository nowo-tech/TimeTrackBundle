<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Since 1.14 (contract §22.6): a validated meetings request — the caller's own meetings overlapping `[from, to)` (UTC
 * instants; the zone only shapes all-day dates), at most `limit`.
 */
final readonly class MeetingQuery
{
    /**
     * Creates the query.
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeZone $timeZone,
        public int $limit,
    ) {
    }
}
