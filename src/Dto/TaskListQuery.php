<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

/**
 * Query parameters when listing trackable tasks for a user.
 */
final readonly class TaskListQuery
{
    public function __construct(
        public ?string $search = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
