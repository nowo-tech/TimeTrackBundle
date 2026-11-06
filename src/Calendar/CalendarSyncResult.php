<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

/**
 * Since 1.14: outcome of one connection sync (counts and error code only).
 */
final readonly class CalendarSyncResult
{
    /**
     * @param array{fetched: int, matched: int, ignored: int, new: int, updated: int, expired: int, stale: int} $counts
     * @param string|null $errorCode Null on success
     */
    public function __construct(
        public string $connectionId,
        public array $counts,
        public ?string $errorCode = null,
    ) {
    }

    /**
     * Whether the sync succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->errorCode === null;
    }
}
