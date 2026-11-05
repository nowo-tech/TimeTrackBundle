<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.13: a locked or submitted period makes entries non-editable and non-deletable whatever a listener grants.
 */
final class TeamAccessGuardLockTest extends TestCase
{
    public function testAGrantingListenerIsIgnoredInALockedPeriod(): void
    {
        $owner      = new TestUser('7', 'ana@example.com');
        $admin      = new TestUser('1', 'admin@example.com', ['ROLE_ADMIN']);
        $timesheets = new InMemoryTimesheetRepository();
        $timesheets->save(TimesheetFixtures::locked($owner, 'org-a', '2026-W45'));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static function (TimeEntryAccessCheckEvent $event): void {
            $event->grant();
        });
        $guard = new TeamAccessGuard(new StubTeamContextProvider(), $dispatcher, ['ROLE_ADMIN'], true, true, null, new PeriodLockGuard($timesheets));

        $locked = (new TimeEntry($owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00Z'), new DateTimeImmutable('2026-11-03 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a');
        $open   = (new TimeEntry($owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a');
        $data   = new ImportedTimeEntryData('clickup:1', 'e-1', 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00Z'), new DateTimeImmutable('2026-11-03 10:00:00Z'), 3600);

        self::assertFalse($guard->canEditEntry($owner, $locked));
        self::assertFalse($guard->canEditEntry($admin, $locked));
        self::assertTrue($guard->canEditEntry($owner, $open));
        self::assertFalse($guard->canDeleteImportedEntry($admin, TimeEntry::imported($owner, $data)));
        self::assertTrue($guard->canDeleteImportedEntry($admin, TimeEntry::imported($owner, new ImportedTimeEntryData('clickup:1', 'e-2', 'task-1', 'T', null, new DateTimeImmutable('2026-11-10 09:00:00Z'), new DateTimeImmutable('2026-11-10 10:00:00Z'), 3600))));
    }
}
