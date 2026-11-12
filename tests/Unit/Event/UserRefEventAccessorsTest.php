<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\TaskEffortAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimesheetAccessCheckEvent;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * 1.23.0 (REQ-ARCH-001 rules 3 and 4): the events carry a framework-free reference of the user next to the deprecated
 * Symfony user accessor; both name the same user.
 */
final class UserRefEventAccessorsTest extends TestCase
{
    public function testEveryEventGivesTheUserReference(): void
    {
        $user  = new TestUser('42', 'ana@example.com', ['ROLE_USER']);
        $entry = new TimeEntry($user, 'task-1', 'Task', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Web);
        $now   = new DateTimeImmutable();

        $refs = [
            (new TimerStartEvent($user, new TaskReference('task-1', 'Task'), ClientType::Web))->userRef(),
            (new TimerStopEvent($user, $entry))->userRef(),
            (new IdleResolvedEvent($user, IdleResolution::Keep, $now, $now, ClientType::Desktop))->userRef(),
            (new TimeEntryAccessCheckEvent($user, $entry, 'view', true))->userRef(),
            (new TaskEffortAccessCheckEvent($user, 'task-1'))->userRef(),
            (new TimesheetAccessCheckEvent($user, '7', 'org-a', 'view', true))->viewerRef(),
            (new TimeEntryListQueryEvent($user, $now, $now))->viewerRef(),
        ];

        foreach ($refs as $ref) {
            self::assertSame([TestUser::class, '42', 'ana@example.com', ['ROLE_USER']], [$ref->userClass, $ref->id, $ref->identifier, $ref->roles]);
            self::assertSame($user, $ref->user());
        }
    }
}
