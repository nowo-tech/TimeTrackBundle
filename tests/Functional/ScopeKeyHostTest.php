<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Command\TimesheetsAutoLockCommand;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\ScopeKeyCollation;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function sort;

/**
 * 1.24.1 (REQ-SEC-006, audit OM): scope keys a host port answers. An unusable legacy key never fails a request
 * (TT-L2), and the auto-lock goes on after a period it cannot lock (TT-M1: a look-alike scope on a database without
 * the 1.24.1 ALTER, whose folding unique key refuses the second timesheet).
 */
final class ScopeKeyHostTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['timesheets' => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class]];
    }

    public function testATimerStopWithAnUnusableHostKeyStillSavesTheEntryWithoutAScope(): void
    {
        $this->scopes()->taskScopes['task-1'] = "org-a\u{200B}";
        $timers                               = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $timers->start($this->user, 'task-1', ClientType::Web);
        $entry = $timers->stop($this->user);

        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertNull($entry->getScopeKey());
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE scope_key IS NULL'));
    }

    public function testAnUnusableHostScopeIsLeftOut(): void
    {
        $this->scopes()->memberships[(string) $this->user->getId()] = ['org-a', 'org-b ', "org-c\u{00A0}", "org-\u{E000}"];
        $guard                                                      = self::getContainer()->get(TimesheetAccessGuard::class);
        self::assertInstanceOf(TimesheetAccessGuard::class, $guard);

        self::assertSame(['org-a'], array_map(static fn (TimesheetScope $scope): string => $scope->key, $guard->scopesOf($this->user)));
        self::assertFalse($guard->hasScope($this->user, 'org-b '));
    }

    public function testTheAutoLockGoesOnAfterAPeriodItCannotLock(): void
    {
        ScopeKeyCollation::fold($this->entityManager()->getConnection());
        $monday = (new DateTimeImmutable('monday this week 08:00'))->modify('-14 days');
        foreach (['org-a', 'Org-a', 'org-z'] as $scope) {
            $entry = (new TimeEntry($this->user, 'task-1', 'T', 'board-1', $monday, $monday->modify('+1 hour'), 3600, TimeEntrySource::Web))->assignScopeKey($scope);
            (new DoctrineOrmTimeEntryRepository($this->entityManager()))->save($entry);
        }
        $timesheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $timesheets);

        $tester = new CommandTester(new TimesheetsAutoLockCommand($timesheets, new DoctrineOrmTimeEntryRepository($this->entityManager()), new TimesheetTimezoneResolver($this->scopes()), 1));
        self::assertSame(Command::FAILURE, $tester->execute(['--lookback-days' => '40']), 'org-a and Org-a share the still-folding unique key: one is refused');
        self::assertStringContainsString('Locked 2 period(s); 1 period(s) could not be locked', $tester->getDisplay());

        $locked = $this->entityManager()->getConnection()->fetchFirstColumn('SELECT scope_key FROM time_track_timesheets WHERE locked_at IS NOT NULL');
        sort($locked);
        self::assertContains('org-z', $locked, 'the run went on after the conflict');
        self::assertCount(2, $locked);
        self::assertSame(2, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_timesheets'));
        self::assertInstanceOf(Timesheet::class, $this->entityManager()->getRepository(Timesheet::class)->findOneBy(['scopeKey' => 'org-z']));
    }

    private function scopes(): FixedScopeProvider
    {
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);

        return $scopes;
    }
}
