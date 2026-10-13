<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Dto;

use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use PHPUnit\Framework\TestCase;

final class TaskListQueryTest extends TestCase
{
    public function testBoundedClampsHugeLimit(): void
    {
        $query = TaskListQuery::bounded('foo', 1_000_000, 5);

        self::assertSame(TaskListQuery::MAX_LIMIT, $query->limit);
        self::assertSame(5, $query->offset);
        self::assertSame('foo', $query->search);
    }

    public function testBoundedClampsLowValues(): void
    {
        $query = TaskListQuery::bounded(null, -10, -3);

        self::assertSame(1, $query->limit);
        self::assertSame(0, $query->offset);
    }

    public function testBoundedKeepsValidLimit(): void
    {
        self::assertSame(50, TaskListQuery::bounded(null, 50, 0)->limit);
    }
}
