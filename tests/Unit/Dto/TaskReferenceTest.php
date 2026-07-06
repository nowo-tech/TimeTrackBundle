<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Dto;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use PHPUnit\Framework\TestCase;

final class TaskReferenceTest extends TestCase
{
    public function testToArray(): void
    {
        $ref = new TaskReference('id-1', 'Title', 'board-1', 'Board');
        self::assertSame([
            'id'         => 'id-1',
            'title'      => 'Title',
            'boardId'    => 'board-1',
            'boardTitle' => 'Board',
        ], $ref->toArray());
    }
}
