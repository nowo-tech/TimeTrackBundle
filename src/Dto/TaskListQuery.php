<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

/**
 * Query parameters when listing trackable tasks for a user.
 */
final readonly class TaskListQuery
{
    /** Upper bound applied to client-supplied page sizes (see {@see self::bounded()}). */
    public const MAX_LIMIT = 100;

    public function __construct(
        public ?string $search = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }

    /**
     * Builds a query from untrusted input: limit clamped to 1..MAX_LIMIT, offset to >= 0.
     */
    public static function bounded(?string $search, int $limit, int $offset): self
    {
        return new self($search, max(1, min(self::MAX_LIMIT, $limit)), max(0, $offset));
    }
}
