<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Command\TimesheetsAutoLockCommand;
use Nowo\TimeTrackBundle\Command\TimesheetsPurgeCommand;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimesheetTransitionEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryDeleter;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryOutcome;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Timesheet\AuditChainVerifier;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetPersonalDataExporter;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_column;
use function array_map;
use function count;
use function implode;

/**
 * Since 1.13 (AC-02 … AC-07, AC-13), against the test database: submit → approve hard-locks the week for every bundle
 * path; reopen restores it with a verifiable audit trail; stale and concurrent approvals; no self-approval; reject
 * comments; tenant isolation; erasure and retention.
 */
final class TimesheetWorkflowDatabaseTest extends FunctionalTestCase
{
    private AppUser $approver;

    private AppUser $admin;

    private TimesheetService $timesheets;

    private Period $week;

    /** @var list<TimesheetTransitionEvent> */
    private array $transitions = [];

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'   => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class, 'audit_blocked_edits' => true],
            'working_time' => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = $this->createUser('boss@example.com');
        $this->admin    = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $owner          = (string) $this->user->getId();
        $boss           = (string) $this->approver->getId();
        $admin          = (string) $this->admin->getId();

        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        // The boss approves the owner in org-a and is a plain member of org-b with them; the admin approves nobody.
        $scopes->memberships = [$owner => ['org-a', 'org-b'], $boss => ['org-a', 'org-b'], $admin => ['org-a']];
        $approvers           = self::getContainer()->get(StubTimesheetApproverProvider::class);
        self::assertInstanceOf(StubTimesheetApproverProvider::class, $approvers);
        $approvers->approvers = ['org-a' => [$boss => [$owner], $admin => [$admin]]];

        $service = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $service);
        $this->timesheets = $service;
        $this->week       = $service->period($this->user, 'org-a', 'previous');

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([TimeTrackEvents::TIMESHEET_SUBMITTED, TimeTrackEvents::TIMESHEET_APPROVED, TimeTrackEvents::TIMESHEET_REOPENED, TimeTrackEvents::TIMESHEET_REJECTED, TimeTrackEvents::TIMESHEET_WITHDRAWN, TimeTrackEvents::TIMESHEET_AUTO_LOCKED] as $name) {
            $dispatcher->addListener($name, function (TimesheetTransitionEvent $event): void {
                $this->transitions[] = $event;
            });
        }
    }

    public function testApprovalHardLocksTheWeekAndReopenRestoresItWithAVerifiableTrail(): void
    {
        $monday = $this->week->startsAt('UTC');
        $this->entry($monday->modify('+8 hours'), 36_000, 'org-a');                 // 10 h on Monday: 2 h overtime
        $this->entry($monday->modify('+1 day +9 hours'), 3600, 'org-b');           // another scope: not part of it
        $writer = $this->writer();
        $writer->upsert($this->user, new ImportedTimeEntryData('clickup:d', 'x-1', 'task-1', 'T', null, $monday->modify('+2 days +9 hours'), $monday->modify('+2 days +10 hours'), 3600));

        $submitted = $this->timesheets->submit($this->user, 'org-a', $this->week, AuditClient::Desktop);
        self::assertSame(TimesheetStatus::Submitted, $submitted->getStatus());
        self::assertSame(36_000, $submitted->getTotals()['taskSeconds'] ?? null);
        self::assertSame(3600, $submitted->getTotals()['importedSeconds'] ?? null);
        self::assertSame(2, $submitted->getTotals()['entries'] ?? null);
        self::assertSame('task_time', $submitted->getTotals()['source'] ?? null);
        self::assertFalse($submitted->getTotals()['legalRecord'] ?? true);

        // Soft lock while submitted (the owner's own writes).
        try {
            $this->timer(new MockClock($monday->modify('+3 days +12 hours')))->startAt($this->user, 'task-1', ClientType::Web, [], $monday->modify('+3 days +11 hours'), new BackdateLimits(86400));
            self::fail('submitted');
        } catch (PeriodSubmittedException) {
        }

        $approved = $this->timesheets->approve($this->approver, $submitted->getId());
        self::assertSame(TimesheetStatus::Approved, $approved->getStatus());
        self::assertSame(TimesheetLockReason::Approved, $approved->getLockReason());
        $ratified = $this->ledgerLines(LedgerKind::OvertimeRatified);
        self::assertCount(1, $ratified);
        self::assertSame(7200, $ratified[0]->getSeconds());

        // AC-02: no bundle path changes the week any more.
        $this->assertEveryWritePathRefuses($monday, $writer);

        // AC-03: reopen with a reason restores editing; the trail is intact and verifiable.
        $reopened = $this->timesheets->reopen($this->approver, $approved->getId(), 'Wrong task on Monday');
        self::assertSame(TimesheetStatus::Open, $reopened->getStatus());
        self::assertFalse($reopened->isLocked());
        self::assertCount(1, $this->ledgerLines(LedgerKind::Reversal), 'ratified overtime is reversed');
        $this->timer(new MockClock($monday->modify('+3 days +12 hours')))->startAt($this->user, 'task-1', ClientType::Web, [], $monday->modify('+3 days +11 hours'), new BackdateLimits(86400));

        $trail = $this->timesheets->events($reopened);
        $rows  = array_map(static fn (TimesheetEvent $e): array => [$e->getAction(), $e->getActorRole(), $e->getClient()], $trail);
        self::assertContains([TimesheetAction::Submitted, TimesheetActorRole::Owner, AuditClient::Desktop], $rows);
        self::assertContains([TimesheetAction::Approved, TimesheetActorRole::Approver, AuditClient::Web], $rows);
        self::assertContains([TimesheetAction::Reopened, TimesheetActorRole::Approver, AuditClient::Web], $rows);
        self::assertContains(TimesheetAction::EditBlocked, array_map(static fn (TimesheetEvent $e): TimesheetAction => $e->getAction(), $trail), 'audit_blocked_edits');
        $last = end($trail);
        self::assertNotFalse($last);
        self::assertSame('Wrong task on Monday', $last->getComment());
        $verifier = self::getContainer()->get(AuditChainVerifier::class);
        self::assertInstanceOf(AuditChainVerifier::class, $verifier);
        self::assertSame([], $verifier->verify());

        // GB-L6: the personal-data export holds the timesheet with its trail and the ledger, without other people's ids.
        $exporter = self::getContainer()->get(TimesheetPersonalDataExporter::class);
        self::assertInstanceOf(TimesheetPersonalDataExporter::class, $exporter);
        $export = $exporter->export((string) $this->user->getId());
        self::assertCount(1, $export['timesheets']);
        self::assertSame($reopened->getId(), $export['timesheets'][0]['id']);
        self::assertCount(count($trail), $export['timesheets'][0]['events']);
        self::assertArrayNotHasKey('actorRef', $export['timesheets'][0]['events'][0]);
        self::assertSame(['overtime_ratified', 'reversal'], array_column($export['ledger'], 'kind'));
        self::assertSame(['timesheets' => [], 'ledger' => []], $exporter->export((string) $this->approver->getId()));
        self::assertSame(['submitted', 'approved', 'reopened'], array_map(static fn (TimesheetTransitionEvent $e): string => $e->toStatus->value === 'open' ? 'reopened' : $e->toStatus->value, $this->transitions));
        self::assertFalse($this->transitions[0]->hasComment);
        self::assertTrue($this->transitions[2]->hasComment);

        // A manual edit of one byte is caught and named.
        $this->entityManager()->getConnection()->executeStatement("UPDATE time_track_timesheet_events SET comment = 'Wrong task on Tuesday' WHERE action = 'reopened'");
        $this->entityManager()->clear();
        $application = new Application(self::$kernel ?? throw new LogicException());
        $verify      = new CommandTester($application->find('nowo:time-track:timesheets:verify'));
        self::assertSame(1, $verify->execute([]));
        self::assertStringContainsString('audit row', $verify->getDisplay());
        self::assertStringContainsString($reopened->getId(), $verify->getDisplay());
    }

    public function testStaleAndConcurrentApprovalsAndNoSelfApproval(): void
    {
        $monday = $this->week->startsAt('UTC');
        $entry  = $this->entry($monday->modify('+8 hours'), 3600, 'org-a');
        $sheet  = $this->timesheets->submit($this->user, 'org-a', $this->week);

        // AC-04: the entries changed since submit (host edit that bypassed the bundle) → stale.
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_entries SET duration_seconds = 60 WHERE id = ?', [$entry->getId()]);
        $this->entityManager()->clear();
        $this->expectTimesheetError('stale', fn (): Timesheet => $this->timesheets->approve($this->approver, $sheet->getId()));
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_entries SET duration_seconds = 3600 WHERE id = ?', [$entry->getId()]);
        $this->entityManager()->clear();

        // Two approvers at once: the other one's write bumped the version first.
        $loaded = $this->timesheets->find($this->approver, $sheet->getId());
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_timesheets SET version = version + 1 WHERE id = ?', [$loaded->getId()]);
        $this->expectTimesheetError('conflict', fn (): Timesheet => $this->timesheets->approve($this->approver, $loaded->getId()));
        $this->entityManager()->clear();
        $this->user     = $this->entityManager()->find(AppUser::class, $this->user->getId()) ?? throw new LogicException();
        $this->approver = $this->entityManager()->find(AppUser::class, $this->approver->getId()) ?? throw new LogicException();
        $this->admin    = $this->entityManager()->find(AppUser::class, $this->admin->getId()) ?? throw new LogicException();

        // AC-05: self-approval is refused even for security.admin_roles holders the provider lists.
        $adminWeek = $this->timesheets->submit($this->admin, 'org-a', $this->week);
        $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->approve($this->admin, $adminWeek->getId()));
        self::assertSame([$adminWeek->getId() => 'not_found', 'missing' => 'not_found'], $this->timesheets->approveMany($this->admin, [$adminWeek->getId(), 'missing']));
        self::assertSame([$sheet->getId() => 'approved'], $this->timesheets->approveMany($this->approver, [$sheet->getId()]));
        $this->expectTimesheetError('invalid_transition', fn (): Timesheet => $this->timesheets->approve($this->approver, $sheet->getId()));
    }

    public function testRejectWithdrawAndSubmitRules(): void
    {
        $this->expectTimesheetError('invalid_transition', fn (): Timesheet => $this->timesheets->withdraw($this->user, 'org-a', $this->week));
        $sheet = $this->timesheets->submit($this->user, 'org-a', $this->week);
        $this->expectTimesheetError('invalid_transition', fn (): Timesheet => $this->timesheets->submit($this->user, 'org-a', $this->week));

        // AC-06: reject needs 10–1000 characters; the comment is kept for the owner until the next submit.
        $this->expectTimesheetError('comment_required', fn (): Timesheet => $this->timesheets->reject($this->approver, $sheet->getId(), ' too short '));
        $rejected = $this->timesheets->reject($this->approver, $sheet->getId(), 'Friday is missing, please add it.');
        self::assertSame(TimesheetStatus::Rejected, $rejected->getStatus());
        self::assertSame('Friday is missing, please add it.', $this->timesheets->view($this->user, (string) $this->user->getId(), 'org-a', $this->week)->getDecisionComment());
        $this->expectTimesheetError('invalid_transition', fn (): Timesheet => $this->timesheets->reject($this->approver, $sheet->getId(), 'Rejected twice in a row.'));
        $this->expectTimesheetError('invalid_transition', fn (): Timesheet => $this->timesheets->reopen($this->approver, $sheet->getId(), 'Not locked, nothing to reopen.'));

        $again = $this->timesheets->submit($this->user, 'org-a', $this->week);
        self::assertNull($again->getDecisionComment());
        $withdrawn = $this->timesheets->withdraw($this->user, 'org-a', $this->week);
        self::assertSame(TimesheetStatus::Open, $withdrawn->getStatus());
        $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->submit($this->approver, 'org-x', $this->week));

        // A running timer that started inside the period of the same scope refuses the submit.
        $timers = new DoctrineOrmActiveTimerRepository($this->entityManager());
        $timers->save(new ActiveTimer($this->user, 'task-1', 'T', 'board-1', ClientType::Web, null, $this->week->startsAt('UTC')->modify('+1 hour')));
        $this->expectTimesheetError('timer_running', fn (): Timesheet => $this->timesheets->submit($this->user, 'org-a', $this->week));
        $this->timesheets->submit($this->user, 'org-b', $this->week);

        $next = $this->timesheets->period($this->user, 'org-a', 'current')->next();
        $this->expectTimesheetError('not_started', fn (): Timesheet => $this->timesheets->submit($this->user, 'org-a', $next));
        $this->expectTimesheetError('not_found', fn (): Period => $this->timesheets->period($this->user, 'org-a', '2026-13'));
        self::assertSame($this->week->key, $this->timesheets->period($this->user, 'org-a', $this->week->key)->key);
        self::assertSame($this->timesheets->period($this->user, 'org-a', null)->key, $this->timesheets->period($this->user, 'org-a', 'current')->key);
    }

    public function testTenantIsolationAnswersLikeANonExistentTimesheet(): void
    {
        // AC-07: the boss approves the owner in org-a, but is only a colleague in org-b.
        $inB = $this->timesheets->submit($this->user, 'org-b', $this->week);
        $inA = $this->timesheets->submit($this->user, 'org-a', $this->week);

        foreach ([$inB->getId(), 'does-not-exist'] as $id) {
            $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->find($this->approver, $id));
            $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->approve($this->approver, $id));
            $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->reject($this->approver, $id, 'A long enough comment.'));
            $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->reopen($this->approver, $id, 'A long enough reason.'));
        }
        $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->view($this->approver, (string) $this->user->getId(), 'org-b', $this->week));
        self::assertSame([$inA->getId()], array_map(static fn (Timesheet $t): string => $t->getId(), $this->timesheets->queue($this->approver, 'org-a')));
        self::assertSame([], $this->timesheets->queue($this->approver, 'org-b'));
        self::assertSame($inA->getId(), $this->timesheets->view($this->approver, (string) $this->user->getId(), 'org-a', $this->week)->getId());
        $this->expectTimesheetError('not_found', fn (): Timesheet => $this->timesheets->view($this->approver, (string) $this->user->getId(), 'org-a', $this->week->previous()));
    }

    public function testAutoLockPurgeAndErasure(): void
    {
        $monday = $this->week->startsAt('UTC')->modify('-14 days');
        $this->entry($monday->modify('+8 hours'), 3600, 'org-a');
        $application = new Application(self::$kernel ?? throw new LogicException());

        $off = new CommandTester($application->find('nowo:time-track:timesheets:auto-lock'));
        self::assertSame(0, $off->execute([]));
        self::assertStringContainsString('Auto-lock is off', $off->getDisplay());

        $command = new TimesheetsAutoLockCommand($this->timesheets, new DoctrineOrmTimeEntryRepository($this->entityManager()), $this->resolver(), 1);
        $tester  = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--lookback-days' => '40']));
        self::assertStringContainsString('Locked 1 period(s).', $tester->getDisplay());
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Locked 0 period(s).', $tester->getDisplay());
        $locked = $this->timesheets->view($this->user, (string) $this->user->getId(), 'org-a', Period::containing($this->timesheets->periodKind(), $monday->format('Y-m-d')));
        self::assertSame(TimesheetLockReason::Auto, $locked->getLockReason());
        self::assertSame(TimesheetStatus::Open, $locked->getStatus());
        self::assertSame(TimesheetActorRole::System, $this->timesheets->events($locked)[0]->getActorRole());
        // An auto-locked period can still be submitted, then approved (the lock reason becomes approved).
        $submitted = $this->timesheets->submit($this->user, 'org-a', $locked->getPeriod());
        self::assertSame(TimesheetLockReason::Approved, $this->timesheets->approve($this->approver, $submitted->getId())->getLockReason());

        // AC-13: erasure keeps the audit records with owner_ref; the purge removes them only after retention.
        $ownerRef = (string) $this->user->getId();
        if ($this->entityManager()->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->entityManager()->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        }
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM time_track_entries');
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM app_user WHERE id = ?', [$ownerRef]);
        $this->entityManager()->clear();
        $kept = $this->entityManager()->find(Timesheet::class, $locked->getId());
        self::assertNotNull($kept);
        self::assertNull($kept->getUser());
        self::assertSame($ownerRef, $kept->getOwnerRef());

        $purge = new CommandTester($application->find('nowo:time-track:timesheets:purge'));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('Purged 0 timesheet(s)', $purge->getDisplay());
        $later = new CommandTester(new TimesheetsPurgeCommand(new DoctrineOrmTimesheetRepository($this->entityManager()), 4, new MockClock((new DateTimeImmutable('+5 years'))->format('Y-m-d H:i:s'))));
        self::assertSame(0, $later->execute(['--batch' => '1']));
        self::assertStringContainsString('Purged 1 timesheet(s)', $later->getDisplay());
        self::assertNull($this->entityManager()->find(Timesheet::class, $locked->getId()));
    }

    public function testAPurgedLedgerChainStillVerifiesAndATruncatedOneDoesNot(): void
    {
        $owner  = (string) $this->user->getId();
        $ledger = new DoctrineOrmBalanceLedgerRepository($this->entityManager());
        $first  = new BalanceLedgerLine($this->user, $owner, 'org-a', '2020-01-01', LedgerKind::Opening, 3600, new DateTimeImmutable('2020-01-01 10:00:00'), null, 'Opening');
        $second = new BalanceLedgerLine($this->user, $owner, 'org-a', '2020-02-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2020-02-01 10:00:00'), $first->getHash(), 'Two');
        $third  = new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-10-01 10:00:00'), $second->getHash(), 'Three');
        foreach ([$first, $second, $third] as $line) {
            $ledger->append($line);
        }
        $ledger->flush();
        $verifier = self::getContainer()->get(AuditChainVerifier::class);
        self::assertInstanceOf(AuditChainVerifier::class, $verifier);

        // GB-L3: the purge removes the old prefix; verify keeps passing (no false tamper alarm).
        self::assertSame(2, (new DoctrineOrmTimesheetRepository($this->entityManager()))->purgeBefore('2021-01-01')['ledger']);
        self::assertSame([], $verifier->verify());

        // Removing the mark (or deleting a chain's oldest lines by hand) is reported.
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_balance_ledger SET purged_before = NULL');
        $this->entityManager()->clear();
        self::assertStringContainsString('not linked', implode(' ', $verifier->verify()));
    }

    private function assertEveryWritePathRefuses(DateTimeImmutable $monday, ImportedTimeEntryWriter $writer): void
    {
        $inWeek = $monday->modify('+3 days +11 hours');
        $clock  = new MockClock($monday->modify('+7 days +2 hours'));
        // Timer start backdated into the week.
        try {
            $this->timer($clock)->startAt($this->user, 'task-1', ClientType::Web, [], $monday->modify('+6 days +23 hours'), new BackdateLimits(86400));
            self::fail('backdated start');
        } catch (PeriodLockedException) {
        }
        // Idle resolve inside the week.
        $timers = new DoctrineOrmActiveTimerRepository($this->entityManager());
        $timers->save(new ActiveTimer($this->user, 'task-1', 'T', 'board-1', ClientType::Web, null, $monday->modify('+6 days +22 hours')));
        try {
            $this->timer($clock, $timers)->resolveIdle($this->user, $monday->modify('+6 days +22 hours +10 minutes'), $monday->modify('+6 days +22 hours +20 minutes'), IdleResolution::Discard);
            self::fail('idle');
        } catch (PeriodLockedException) {
        }
        $timers->remove($timers->findByUserId((string) $this->user->getId()) ?? throw new LogicException());
        // Import, edit with a granting listener, delete.
        self::assertSame(ImportedTimeEntryOutcome::Locked, $writer->upsert($this->user, new ImportedTimeEntryData('clickup:d', 'x-2', 'task-1', 'T', null, $inWeek, $inWeek->modify('+1 hour'), 3600))->outcome);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK, static fn (TimeEntryAccessCheckEvent $e) => $e->grant());
        $guard   = new TeamAccessGuard(new NullTeamContextProvider(), $dispatcher, ['ROLE_ADMIN'], true, true, null, $this->lockGuard());
        $entries = (new DoctrineOrmTimeEntryRepository($this->entityManager()))->findByUserAndPeriod((string) $this->user->getId(), $monday, $monday->modify('+7 days'));
        foreach ($entries as $entry) {
            if ($entry->getScopeKey() === 'org-a') {
                self::assertFalse($guard->canEditEntry($this->admin, $entry));
            }
            if ($entry->isReadOnly()) {
                self::assertFalse($guard->canDeleteImportedEntry($this->admin, $entry));
                try {
                    (new ImportedTimeEntryDeleter($guard, $writer))->delete($this->admin, $entry);
                    self::fail('delete');
                } catch (AccessDeniedException) {
                }
            }
        }
        // Ledger.
        $ledger = self::getContainer()->get(BalanceLedger::class);
        self::assertInstanceOf(BalanceLedger::class, $ledger);
        try {
            $ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, 600, $monday->format('Y-m-d'), 'Locked week');
            self::fail('ledger');
        } catch (PeriodLockedException) {
        }
        try {
            $ledger->add($this->user, $this->user, 'org-a', LedgerKind::Adjustment, 600, $monday->format('Y-m-d'), 'Self adjustment');
            self::fail('self');
        } catch (LedgerRefusedException $e) {
            self::assertSame('forbidden', $e->errorCode());
        }
    }

    private function entry(DateTimeImmutable $start, int $seconds, string $scope): TimeEntry
    {
        $entry = (new TimeEntry($this->user, 'task-1', 'T', $scope === 'org-b' ? 'board-2' : 'board-1', $start, $start->modify('+' . $seconds . ' seconds'), $seconds, TimeEntrySource::Web))->assignScopeKey($scope);
        (new DoctrineOrmTimeEntryRepository($this->entityManager()))->save($entry);

        return $entry;
    }

    private function writer(): ImportedTimeEntryWriter
    {
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);

        return new ImportedTimeEntryWriter(new DoctrineOrmTimeEntryRepository($this->entityManager()), periodLockGuard: $this->lockGuard(), scopeProvider: $scopes);
    }

    private function timer(MockClock $clock, ?DoctrineOrmActiveTimerRepository $timers = null): TimerService
    {
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);

        return new TimerService(new StubTaskProvider(), $timers ?? new DoctrineOrmActiveTimerRepository($this->entityManager()), new DoctrineOrmTimeEntryRepository($this->entityManager()), new EventDispatcher(), $clock, null, $this->lockGuard(), $scopes);
    }

    private function lockGuard(): PeriodLockGuard
    {
        $guard = self::getContainer()->get(PeriodLockGuard::class);
        self::assertInstanceOf(PeriodLockGuard::class, $guard);

        return $guard;
    }

    private function resolver(): TimesheetTimezoneResolver
    {
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);

        return new TimesheetTimezoneResolver($scopes);
    }

    /**
     * @return list<BalanceLedgerLine>
     */
    private function ledgerLines(LedgerKind $kind): array
    {
        $lines = (new DoctrineOrmBalanceLedgerRepository($this->entityManager()))->findByOwner((string) $this->user->getId(), 'org-a');

        return array_values(array_filter($lines, static fn (BalanceLedgerLine $l): bool => $l->getKind() === $kind));
    }

    private function expectTimesheetError(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('expected ' . $code);
        } catch (TimesheetException $e) {
            self::assertSame($code, $e->errorCode());
        }
    }
}
