<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetEventRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAuditTrail;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormHandler;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetSnapshotBuilder;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 1.13: the web form handler of the components (without FormKit: the plain field path of the form types).
 */
final class TimesheetFormHandlerTest extends TestCase
{
    private TestUser $owner;

    private TestUser $approver;

    private TimesheetService $service;

    private TimesheetFormHandler $handler;

    private InMemoryBalanceLedgerRepository $lines;

    protected function setUp(): void
    {
        $this->owner    = new TestUser('7', 'ana@example.com');
        $this->approver = new TestUser('9', 'boss@example.com');
        $timesheets     = new InMemoryTimesheetRepository();
        $events         = new InMemoryTimesheetEventRepository();
        $entries        = new InMemoryTimeEntryRepository();
        $entries->save((new TimeEntry($this->owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-02 09:00:00Z'), new DateTimeImmutable('2026-11-02 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a'));
        $scopes        = new FixedScopeProvider(['7' => ['org-a'], '9' => ['org-a']]);
        $guard         = new TimesheetAccessGuard($scopes, new StubTimesheetApproverProvider(['org-a' => ['9' => ['7']]]));
        $this->lines   = new InMemoryBalanceLedgerRepository();
        $ledger        = new BalanceLedger($this->lines, $guard, new PeriodLockGuard($timesheets));
        $this->service = new TimesheetService($timesheets, $events, new TimesheetAuditTrail($events, $timesheets), $guard, new TimesheetSnapshotBuilder($entries), new TimesheetTimezoneResolver($scopes), new InMemoryActiveTimerRepository(), $ledger, null, new MockClock('2026-11-10 10:00:00'));
        $forms         = Forms::createFormFactoryBuilder()->addExtension(new HttpFoundationExtension())->getFormFactory();
        $this->handler = new TimesheetFormHandler($forms, $this->service, $ledger);
    }

    public function testEveryTimesheetActionGoesThroughTheService(): void
    {
        $week = $this->service->period($this->owner, 'org-a', '2026-W45');
        $id   = $this->service->view($this->owner, '7', 'org-a', $week)->getId();

        self::assertSame('/host/submit', $this->handler->decisionForm('submit', null, '/host/submit')->getConfig()->getAction());
        self::assertTrue($this->handler->decisionForm('reject')->has('comment'));
        self::assertFalse($this->handler->decisionForm('approve')->has('comment'));

        self::assertSame(TimesheetStatus::Submitted, $this->handler->handleDecision($this->post('time_track_submit', ['timesheet' => $id]), $this->owner, 'submit')->getStatus());
        self::assertSame(TimesheetStatus::Open, $this->handler->handleDecision($this->post('time_track_withdraw', ['timesheet' => $id]), $this->owner, 'withdraw')->getStatus());
        $this->handler->handleDecision($this->post('time_track_submit', ['timesheet' => $id]), $this->owner, 'submit');
        self::assertSame(TimesheetStatus::Rejected, $this->handler->handleDecision($this->post('time_track_reject', ['timesheet' => $id, 'comment' => 'Please add Friday.']), $this->approver, 'reject')->getStatus());
        $this->handler->handleDecision($this->post('time_track_submit', ['timesheet' => $id]), $this->owner, 'submit');
        self::assertSame(TimesheetStatus::Approved, $this->handler->handleDecision($this->post('time_track_approve', ['timesheet' => $id]), $this->approver, 'approve')->getStatus());
        self::assertSame(TimesheetStatus::Open, $this->handler->handleDecision($this->post('time_track_reopen', ['timesheet' => $id, 'comment' => 'Fix the Monday task.']), $this->approver, 'reopen')->getStatus());

        foreach ([['delete', 'time_track_delete', []], ['approve', 'time_track_other', ['timesheet' => $id]]] as [$action, $name, $data]) {
            try {
                $this->handler->handleDecision($this->post($name, $data), $this->approver, $action);
                self::fail($action);
            } catch (TimesheetException $e) {
                self::assertSame('invalid_request', $e->errorCode());
                self::assertSame(400, $e->httpStatus());
            }
        }
        self::assertSame(TimesheetPeriodKind::Week, $this->service->periodKind());
    }

    public function testLedgerLinesAndReversals(): void
    {
        $line = $this->handler->handleLedger($this->post('time_track_ledger', ['kind' => 'adjustment', 'minutes' => '-30', 'effective_date' => '2026-10-02', 'reason' => 'Doctor visit']), $this->approver, $this->owner, 'org-a');
        self::assertSame(-1800, $line->getSeconds());
        self::assertSame(LedgerKind::Adjustment, $line->getKind());

        $undo = $this->handler->handleLedger($this->post('time_track_ledger_reversal', ['reverses' => $line->getId(), 'effective_date' => '2026-10-03', 'reason' => 'Wrong day']), $this->approver, $this->owner, 'org-a', true);
        self::assertSame(1800, $undo->getSeconds());
        self::assertTrue($this->handler->ledgerForm('/host/ledger/reverse', $line->getId())->has('reverses'));
        self::assertTrue($this->handler->ledgerForm('/host/ledger')->has('kind'));

        foreach ([['kind' => 'overtime_ratified', 'minutes' => '5', 'effective_date' => '2026-10-02', 'reason' => 'x'], ['kind' => 'nope']] as $data) {
            try {
                $this->handler->handleLedger($this->post('time_track_ledger', $data), $this->approver, $this->owner, 'org-a');
                self::fail('invalid');
            } catch (LedgerRefusedException $e) {
                self::assertSame('invalid_request', $e->errorCode());
            }
        }
        $this->expectException(LedgerRefusedException::class);
        (new TimesheetFormHandler(Forms::createFormFactory(), $this->service))->handleLedger(new Request(), $this->approver, $this->owner, 'org-a');
    }

    /**
     * @param array<string, string> $data
     */
    private function post(string $form, array $data): Request
    {
        return Request::create('/host', 'POST', [$form => $data]);
    }
}
