<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Dto;

use Nowo\TimeTrackBundle\Dto\TaskReference;
use PHPUnit\Framework\TestCase;

use function array_keys;

final class TaskReferenceTest extends TestCase
{
    public function testToArray(): void
    {
        $ref = new TaskReference('id-1', 'Title', 'board-1', 'Board');
        self::assertSame([
            'id'              => 'id-1',
            'title'           => 'Title',
            'boardId'         => 'board-1',
            'boardTitle'      => 'Board',
            'projectId'       => null,
            'projectTitle'    => null,
            'url'             => null,
            'estimateSeconds' => null,
            'loggedSeconds'   => null,
        ], $ref->toArray());
    }

    public function testProjectAndUrlSince110(): void
    {
        $ref = new TaskReference('id-1', 'Title', projectId: 'p-1', projectTitle: 'Website', url: 'https://host.example/t/1');

        self::assertSame(['id', 'title', 'boardId', 'boardTitle', 'projectId', 'projectTitle', 'url', 'estimateSeconds', 'loggedSeconds'], array_keys($ref->toArray()));
        self::assertSame('p-1', $ref->toArray()['projectId']);
        self::assertSame('Website', $ref->toArray()['projectTitle']);
        self::assertSame('https://host.example/t/1', $ref->toArray()['url']);
        self::assertNull($ref->boardId);
    }
}
