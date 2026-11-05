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
use Nowo\TimeTrackBundle\Form\LedgerLineType;
use Nowo\TimeTrackBundle\Form\TimesheetDecisionType;
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
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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
        $this->handler = new TimesheetFormHandler($forms, $this->service, $ledger, $this->csrf());
    }

    public function testEveryTimesheetActionGoesThroughTheService(): void
    {
        $week = $this->service->period($this->owner, 'org-a', '2026-W45');
        $id   = $this->service->view($this->owner, '7', 'org-a', $week)->getId();
        self::assertNull($this->handler->decisionForm('submit', $this->service->view($this->owner, '7', 'org-a', $this->service->period($this->owner, 'org-a', '2026-W40')))->get('timesheet')->getData(), 'a draft has no id');

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

    public function testOwnerActionsUseTheActorsOwnPeriod(): void
    {
        // A period without a stored row (a draft view) is submitted by scope + period key.
        $submitted = $this->handler->handleDecision($this->post('time_track_submit', ['scope' => 'org-a', 'period' => '2026-W40']), $this->owner, 'submit');
        self::assertSame('2026-W40', $submitted->getPeriod()->key);
        self::assertSame(TimesheetStatus::Submitted, $submitted->getStatus());

        // GB-I3: someone else's timesheet id is not found, it never acts on the actor's own period by accident.
        $theirs = $this->service->view($this->approver, '9', 'org-a', $this->service->period($this->approver, 'org-a', 'current'))->getId();
        try {
            $this->handler->handleDecision($this->post('time_track_submit', ['timesheet' => $theirs]), $this->owner, 'submit');
            self::fail('foreign id');
        } catch (TimesheetException $e) {
            self::assertSame('not_found', $e->errorCode());
        }
        // An approver posting the owner's id (a timesheet they may view) does not submit anything: not the owner's.
        $owners = $this->service->view($this->owner, '7', 'org-a', $this->service->period($this->owner, 'org-a', 'current'))->getId();
        try {
            $this->handler->handleDecision($this->post('time_track_submit', ['timesheet' => $owners]), $this->approver, 'submit');
            self::fail('approver id');
        } catch (TimesheetException $e) {
            self::assertSame('not_found', $e->errorCode());
        }
        // A scope the actor is not in is not found either.
        try {
            $this->handler->handleDecision($this->post('time_track_submit', ['scope' => 'org-x', 'period' => '2026-W40']), $this->owner, 'submit');
            self::fail('foreign scope');
        } catch (TimesheetException $e) {
            self::assertSame('not_found', $e->errorCode());
        }
    }

    public function testCsrfIsCheckedWhateverTheFormConfigurationSays(): void
    {
        $forms = Forms::createFormFactoryBuilder()->addExtension(new HttpFoundationExtension())->getFormFactory();
        $id    = $this->service->view($this->owner, '7', 'org-a', $this->service->period($this->owner, 'org-a', '2026-W45'))->getId();
        // GB-L2: forms without the CSRF extension, a wrong or missing token, and no token manager are all refused.
        foreach ([[$this->handler, 'wrong'], [$this->handler, null], [new TimesheetFormHandler($forms, $this->service), 'valid-' . TimesheetDecisionType::CSRF_TOKEN_ID]] as [$handler, $token]) {
            try {
                $handler->handleDecision($this->post('time_track_submit', ['timesheet' => $id], $token), $this->owner, 'submit');
                self::fail('csrf');
            } catch (TimesheetException $e) {
                self::assertSame('invalid_request', $e->errorCode());
            }
        }
        try {
            $this->handler->handleLedger($this->post('time_track_ledger', ['kind' => 'adjustment', 'minutes' => '5', 'effective_date' => '2026-10-02', 'reason' => 'x'], 'wrong'), $this->approver, $this->owner, 'org-a');
            self::fail('ledger csrf');
        } catch (LedgerRefusedException $e) {
            self::assertSame('invalid_request', $e->errorCode());
        }
        self::assertSame([], $this->lines->lines);
    }

    public function testHugeMinutesAreInvalidSecondsNotAnOverflow(): void
    {
        $this->expectException(LedgerRefusedException::class);
        $this->expectExceptionMessage('invalid_seconds');

        $this->handler->handleLedger($this->post('time_track_ledger', ['kind' => 'adjustment', 'minutes' => '9000000000000000', 'effective_date' => '2026-10-02', 'reason' => 'Overflow']), $this->approver, $this->owner, 'org-a');
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
    private function post(string $form, array $data, ?string $token = ''): Request
    {
        if ($token === '') {
            $token = 'valid-' . ($form === 'time_track_ledger' || $form === 'time_track_ledger_reversal' ? LedgerLineType::CSRF_TOKEN_ID : TimesheetDecisionType::CSRF_TOKEN_ID);
        }
        if ($token !== null) {
            $data['_token'] = $token;
        }

        return Request::create('/host', 'POST', [$form => $data]);
    }

    /**
     * A token manager that accepts `valid-<token id>`.
     */
    private function csrf(): CsrfTokenManagerInterface
    {
        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'valid-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return $token->getValue() === 'valid-' . $token->getId();
            }
        };
    }
}
