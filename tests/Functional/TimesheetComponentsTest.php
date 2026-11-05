<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Twig\TimeTrackComponentRuntime;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * Since 1.13 (contract §16a, AC-08): the configurable components render for owners and approvers (masked titles, no
 * notes by default), offer only the actions the guard allows and use the UiKit dialog anatomy; no template override.
 */
final class TimesheetComponentsTest extends FunctionalTestCase
{
    private AppUser $approver;

    private Environment $twig;

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'   => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'working_time' => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = $this->createUser('boss@example.com');
        $scopes         = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a'], (string) $this->approver->getId() => ['org-a']];
        $approvers           = self::getContainer()->get(StubTimesheetApproverProvider::class);
        self::assertInstanceOf(StubTimesheetApproverProvider::class, $approvers);
        $approvers->approvers = ['org-a' => [(string) $this->approver->getId() => [(string) $this->user->getId()]]];
        $request              = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testTheWeekGridForTheOwnerAndTheApprover(): void
    {
        $sheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $sheets);
        $week   = $sheets->period($this->user, 'org-a', 'previous');
        $monday = $week->startsAt('UTC');
        $repo   = new DoctrineOrmTimeEntryRepository($this->entityManager());
        $repo->save((new TimeEntry($this->user, 'task-1', 'Implement login API', 'board-1', $monday->modify('+8 hours'), $monday->modify('+10 hours'), 7200, TimeEntrySource::Web, ['note' => 'Private note']))->assignScopeKey('org-a'));
        $repo->save((new TimeEntry($this->user, 'task-secret', 'Secret project', 'board-1', $monday->modify('+11 hours'), $monday->modify('+12 hours'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a'));
        $repo->save(new TimeEntry($this->user, 'task-1', 'Implement login API', 'board-1', $monday->modify('+13 hours'), $monday->modify('+14 hours'), 3600, TimeEntrySource::Web));
        $timesheet = $sheets->view($this->user, (string) $this->user->getId(), 'org-a', $week);
        $urls      = ['submit' => '/host/submit', 'withdraw' => '/host/withdraw', 'approve' => '/host/approve', 'reject' => '/host/reject', 'reopen' => '/host/reopen'];

        $owner = $this->render('TimeTrack:TimesheetWeekGrid', ['timesheet' => $timesheet, 'viewer' => $this->user, 'options' => ['action_urls' => $urls, 'density' => 'compact', 'labels' => ['task' => 'Assignment']]]);
        self::assertStringContainsString('data-testid="time-track-week-grid"', $owner);
        self::assertStringContainsString('nowo-time-track--compact', $owner);
        self::assertStringContainsString('Assignment', $owner);
        self::assertStringContainsString('Implement login API', $owner);
        self::assertStringContainsString('Private note', $owner, 'owners see their notes');
        self::assertStringContainsString('2:00', $owner);
        self::assertStringContainsString('/host/submit', $owner);
        self::assertStringNotContainsString('/host/approve', $owner);
        self::assertStringContainsString('time-track-week-unassigned', $owner);
        self::assertStringContainsString('time-track-week-expected', $owner);
        self::assertStringContainsString('Task time, not a working-time record', $owner);

        $submitted = $sheets->submit($this->user, 'org-a', $week);
        $approver  = $this->render('TimesheetWeekGrid', ['timesheet' => $submitted, 'viewer' => $this->approver, 'options' => ['action_urls' => $urls, 'show_expected' => false]]);
        self::assertStringContainsString('Task not visible', $approver, 'AC-08: a task the approver cannot see is masked');
        self::assertStringNotContainsString('Secret project', $approver);
        self::assertStringNotContainsString('Private note', $approver, 'AC-08: no notes by default');
        self::assertStringContainsString('/host/approve', $approver);
        self::assertStringContainsString('nowo-ui-confirm-dialog--anatomy', $approver, 'reject is a UiKit 2.29 dialog');
        self::assertStringContainsString('form="time-track-reject-' . $submitted->getId() . '-form"', $approver);
        self::assertStringNotContainsString('/host/withdraw', $approver);
        self::assertStringNotContainsString('time-track-week-expected', $approver);
        self::assertStringNotContainsString('time-track-week-unassigned', $approver);

        $approved = $sheets->approve($this->approver, $submitted->getId());
        $reopen   = $this->render('TimesheetWeekGrid', ['timesheet' => $approved, 'viewer' => $this->approver, 'options' => ['action_urls' => $urls, 'actions' => ['reopen']]]);
        self::assertStringContainsString('/host/reopen', $reopen);
        self::assertStringContainsString('time-track-week-locked', $reopen);
        $readOnly = $this->render('TimesheetWeekGrid', ['timesheet' => $sheets->reopen($this->approver, $approved->getId(), 'Reopened for a fix.'), 'viewer' => $this->user, 'options' => ['action_urls' => $urls, 'read_only' => true]]);
        self::assertStringNotContainsString('/host/submit', $readOnly);
        self::assertStringContainsString('Reopened for a fix.', $readOnly);
    }

    public function testTheBalanceCardAndTheLedgerTable(): void
    {
        $balance = self::getContainer()->get(WorkingTimeBalanceService::class);
        self::assertInstanceOf(WorkingTimeBalanceService::class, $balance);
        $ledger = self::getContainer()->get(BalanceLedger::class);
        self::assertInstanceOf(BalanceLedger::class, $ledger);
        $report = $balance->report($this->user, 'org-a', '2026-10-01', '2026-10-31');
        self::assertNotNull($report);

        $card = $this->render('BalanceCard', ['report' => $report, 'options' => ['range' => 'month', 'labels' => ['title' => 'My balance']]]);
        self::assertStringContainsString('My balance', $card);
        self::assertStringContainsString('time-track-balance-legal', $card);
        self::assertStringContainsString('time-track-balance-overtime', $card);
        self::assertStringNotContainsString('time-track-balance-overtime', $this->render('BalanceCard', ['report' => $report, 'options' => ['show_overtime' => false]]));

        $line = $ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, -1800, '2026-10-02', 'Doctor visit');
        $ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Reversal, 0, '2026-10-03', 'Wrong day', $ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Opening, 3600, '2026-10-01', 'Opening')->getId());
        $lines = $ledger->linesOf((string) $this->user->getId(), 'org-a');
        $urls  = ['add_adjustment' => '/host/ledger', 'reversal' => '/host/ledger/reverse'];

        $table = $this->render('LedgerTable', ['lines' => $lines, 'viewer' => $this->approver, 'owner_id' => (string) $this->user->getId(), 'scope_key' => 'org-a', 'options' => ['action_urls' => $urls]]);
        self::assertStringContainsString('Doctor visit', $table);
        self::assertStringContainsString('-0:30', $table);
        self::assertStringContainsString('/host/ledger/reverse', $table);
        self::assertStringContainsString('data-line="' . $line->getId() . '"', $table);
        self::assertStringContainsString('data-reversed="1"', $table);
        self::assertStringContainsString('time-track-ledger-add', $table);
        $ownerView = $this->render('LedgerTable', ['lines' => $lines, 'viewer' => $this->user, 'owner_id' => (string) $this->user->getId(), 'scope_key' => 'org-a', 'options' => ['action_urls' => $urls, 'columns' => ['kind', 'seconds']]]);
        self::assertStringNotContainsString('/host/ledger', $ownerView, 'owners never adjust their own balance');
        self::assertStringNotContainsString('Doctor visit', $ownerView, 'the reason column is hidden');
        self::assertStringContainsString('No movements.', $this->render('LedgerTable', ['lines' => [], 'viewer' => $this->user, 'owner_id' => 'x', 'scope_key' => 'org-a']));
    }

    public function testBadComponentCalls(): void
    {
        foreach ([['Nope', []], ['BalanceCard', []], ['LedgerTable', ['lines' => [], 'viewer' => $this->user]]] as [$name, $props]) {
            try {
                $this->render($name, $props);
                self::fail($name);
            } catch (RuntimeError $e) {
                self::assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
            }
        }
        self::assertSame('-1:01', TimeTrackComponentRuntime::duration(-3660));
        self::assertSame('+0:59', TimeTrackComponentRuntime::duration(3599, true));
        self::assertSame('0:00', TimeTrackComponentRuntime::duration(0, true));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $name, array $props): string
    {
        return $this->twig->createTemplate('{{ nowo_time_track_component(name, props) }}')->render(['name' => $name, 'props' => $props]);
    }
}
