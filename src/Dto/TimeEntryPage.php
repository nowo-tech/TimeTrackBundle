<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

use Nowo\TimeTrackBundle\Entity\TimeEntry;

use function intdiv;
use function max;

/**
 * One page of time entries (reports list, REQ-PERF-001): the entries of the page plus the totals needed to render the
 * pagination.
 */
final readonly class TimeEntryPage
{
    /**
     * @param list<TimeEntry> $entries Entries of the page (newest first)
     * @param int $page 1-based page number
     * @param int $perPage Page size
     * @param int $total Entries of the whole period
     */
    public function __construct(
        public array $entries,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * Number of pages (at least 1, so an empty period still renders page 1 of 1).
     */
    public function totalPages(): int
    {
        return max(1, intdiv($this->total + $this->perPage - 1, max(1, $this->perPage)));
    }

    /**
     * Whether a page follows this one.
     */
    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    /**
     * Whether a page precedes this one.
     */
    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }
}
