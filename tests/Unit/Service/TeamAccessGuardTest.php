<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class TeamAccessGuardTest extends TestCase
{
    public function testUserCanViewOwnEntries(): void
    {
        $guard = new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true);
        $user  = new TestUser('1', 'u@example.com');

        self::assertTrue($guard->canViewUserEntries($user, '1'));
    }

    public function testAdminCanViewOthers(): void
    {
        $guard = new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true);
        $admin = new TestUser('1', 'admin@example.com', ['ROLE_ADMIN']);

        self::assertTrue($guard->canViewUserEntries($admin, '99'));
    }

    public function testCanEditOwnEntry(): void
    {
        $guard = new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true);
        $user  = new TestUser('1', 'u@example.com');
        $entry = new TimeEntry(
            $user,
            'task-1',
            'Task',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        self::assertTrue($guard->canEditEntry($user, $entry));
    }

    public function testManagerViewDisabledReturnsFalse(): void
    {
        $guard = new TeamAccessGuard(new StubTeamContextProvider(['99'], true), new EventDispatcher(), [], false, true);
        $user  = new TestUser('1', 'manager@example.com');

        self::assertFalse($guard->canViewUserEntries($user, '99'));
    }

    public function testManagerViewWithNoManagedMatch(): void
    {
        $guard = new TeamAccessGuard(new StubTeamContextProvider(['88'], true), new EventDispatcher(), [], true, true);
        $user  = new TestUser('1', 'manager@example.com');

        self::assertFalse($guard->canViewUserEntries($user, '99'));
    }

    public function testAdminRoleGrantsEdit(): void
    {
        $admin = new TestUser('1', 'admin@example.com', ['ROLE_ADMIN']);
        $other = new TestUser('2', 'other@example.com');
        $entry = new TimeEntry(
            $other,
            'task-1',
            'Task',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        $guard = new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, false);

        self::assertTrue($guard->canEditEntry($admin, $entry));
    }

    public function testCannotEditWhenNotOwnerAndNoManagerRights(): void
    {
        $editor = new TestUser('1', 'editor@example.com');
        $owner  = new TestUser('2', 'owner@example.com');
        $entry  = new TimeEntry(
            $owner,
            'task-1',
            'Task',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        $guard = new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), [], true, false);

        self::assertFalse($guard->canEditEntry($editor, $entry));
    }

    public function testManagerCanViewManagedUserEntries(): void
    {
        $guard = new TeamAccessGuard(
            new StubTeamContextProvider(['99'], true),
            new EventDispatcher(),
            ['ROLE_ADMIN'],
            true,
            false,
        );
        $manager = new TestUser('1', 'manager@example.com');

        self::assertTrue($guard->canViewUserEntries($manager, '99'));
    }

    public function testNonManagerCannotViewOthers(): void
    {
        $guard = new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), ['ROLE_ADMIN'], true, true);
        $user  = new TestUser('1', 'u@example.com');

        self::assertFalse($guard->canViewUserEntries($user, '99'));
    }

    public function testManagerCanEditEntryWhenEnabled(): void
    {
        $manager = new TestUser('1', 'manager@example.com');
        $member  = new TestUser('2', 'member@example.com');
        $entry   = new TimeEntry(
            $member,
            'task-1',
            'Task',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        $guard = new TeamAccessGuard(
            new StubTeamContextProvider([], true),
            new EventDispatcher(),
            [],
            true,
            true,
        );

        self::assertTrue($guard->canEditEntry($manager, $entry));
    }

    public function testAccessCheckEventCanDenyEdit(): void
    {
        $user  = new TestUser('1', 'u@example.com');
        $entry = new TimeEntry(
            $user,
            'task-1',
            'Task',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK,
            static function (TimeEntryAccessCheckEvent $event): void {
                $event->deny();
            },
        );

        $guard = new TeamAccessGuard(new StubTeamContextProvider(), $dispatcher, [], true, true);

        self::assertFalse($guard->canEditEntry($user, $entry));
    }

    public function testCannotEditWhenEntryUserIsNotUserInterface(): void
    {
        $guard = new TeamAccessGuard(new StubTeamContextProvider(), new EventDispatcher(), [], true, true);
        $entry = new TimeEntry(
            new class {
                public function getId(): string
                {
                    return 'x';
                }
            },
            'task-1',
            'Task',
            null,
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable(),
            3600,
            TimeEntrySource::Web,
        );

        self::assertFalse($guard->canEditEntry(new TestUser('1', 'u@example.com'), $entry));
    }
}
