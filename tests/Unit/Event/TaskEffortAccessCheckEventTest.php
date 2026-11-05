<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Event;

use Nowo\TimeTrackBundle\Event\TaskEffortAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.13 (owner decision 2026-11-05, audit GB-M3): the all-users task effort is denied by default.
 */
final class TaskEffortAccessCheckEventTest extends TestCase
{
    public function testDeniedByDefaultAndGrantedOnlyByAListener(): void
    {
        $user  = new TestUser('7', 'ana@example.com', ['ROLE_ADMIN']);
        $event = new TaskEffortAccessCheckEvent($user, 'task-1');
        self::assertSame($user, $event->getUser());
        self::assertSame('task-1', $event->getTaskId());
        self::assertFalse($event->isGranted());
        $event->grant();
        self::assertTrue($event->isGranted());
        $event->deny();
        self::assertFalse($event->isGranted());

        $dispatcher = new EventDispatcher();
        $guard      = new TeamAccessGuard(new StubTeamContextProvider(), $dispatcher, ['ROLE_ADMIN'], true, true);
        self::assertFalse($guard->canSeeTaskEffortTotal($user, 'task-1'), 'admin roles do not see it by default');
        $dispatcher->addListener(TimeTrackEvents::TASK_EFFORT_ACCESS_CHECK, static function (TaskEffortAccessCheckEvent $event): void {
            if ($event->getTaskId() === 'task-1') {
                $event->grant();
            }
        });
        self::assertTrue($guard->canSeeTaskEffortTotal($user, 'task-1'));
        self::assertFalse($guard->canSeeTaskEffortTotal($user, 'task-2'));
    }
}
