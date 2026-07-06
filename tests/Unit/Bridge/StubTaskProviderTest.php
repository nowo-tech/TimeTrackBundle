<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Bridge;

use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class StubTaskProviderTest extends TestCase
{
    public function testListsAndFindsTasks(): void
    {
        $provider = new StubTaskProvider();
        $user     = new TestUser('1', 'u@example.com');

        self::assertNotNull($provider->findForUser('task-1', $user));
        self::assertNull($provider->findForUser('missing', $user));
        self::assertCount(3, $provider->listTrackableForUser($user, new TaskListQuery()));
        self::assertTrue($provider->canUserTrack($user, 'task-1'));
        self::assertFalse($provider->canUserTrack($user, 'missing'));
    }

    public function testSearchFiltersTasks(): void
    {
        $provider = new StubTaskProvider();
        $user     = new TestUser('1', 'u@example.com');
        $query    = new TaskListQuery(search: 'unit', limit: 10, offset: 0);

        $tasks = $provider->listTrackableForUser($user, $query);

        self::assertCount(1, $tasks);
        self::assertSame('task-2', $tasks[0]->id);
    }
}
