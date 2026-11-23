<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use LogicException;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Form\LedgerLineType;
use Nowo\TimeTrackBundle\Form\TimesheetDecisionType;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormHandler;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Ui\TimesheetDialogResponder;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function fwrite;
use function sprintf;
use function strlen;
use function substr_count;

use const STDERR;

/**
 * Since 1.39.0 (symfony-beacon rule 2026-11-23, "every dialog loads its content on demand"): with `dialog_urls` the
 * ledger table and the week grid render links and ONE empty remote dialog, never a form or a dialog body per row; the
 * dialog URL serves the fragment for `X-Nowo-Dialog: 1` and the full page otherwise, answers 422 in place, 409 on a
 * conflict, 204 + `X-Nowo-Redirect` once saved, and refuses access exactly as the post does.
 */
final class TimesheetDialogsTest extends FunctionalTestCase
{
    private AppUser $approver;

    private Environment $twig;

    private Session $session;

    private RequestStack $stack;

    private BalanceLedger $ledger;

    private TimesheetDialogResponder $dialogs;

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
        $this->session        = new Session(new MockArraySessionStorage());
        $stack                = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $this->stack = $stack;
        $this->push(new Request());
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
        $ledger     = self::getContainer()->get(BalanceLedger::class);
        self::assertInstanceOf(BalanceLedger::class, $ledger);
        $this->ledger = $ledger;
        $dialogs      = self::getContainer()->get(TimesheetDialogResponder::class);
        self::assertInstanceOf(TimesheetDialogResponder::class, $dialogs);
        $this->dialogs = $dialogs;
    }

    public function testFiftyLedgerRowsRenderNoFormAndNoDialogBody(): void
    {
        for ($i = 1; $i <= 50; ++$i) {
            $this->ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, 60 * $i, '2026-10-' . sprintf('%02d', 1 + $i % 28), 'Movement ' . $i);
        }
        $lines = $this->ledger->linesOf((string) $this->user->getId(), 'org-a');
        self::assertCount(50, $lines);
        $props = ['lines' => $lines, 'viewer' => $this->approver, 'owner_id' => (string) $this->user->getId(), 'scope_key' => 'org-a'];

        $inline = $this->render('LedgerTable', $props + ['options' => ['action_urls' => ['add_adjustment' => '/host/ledger', 'reversal' => '/host/ledger/reverse']]]);
        $lazy   = $this->render('LedgerTable', $props + ['options' => ['dialog_urls' => ['add_adjustment' => '/host/ledger/add', 'reversal' => '/host/ledger/reverse']]]);
        fwrite(STDERR, sprintf("\n[lazy dialogs] LedgerTable, 50 rows: inline %d bytes, on demand %d bytes\n", strlen($inline), strlen($lazy)));

        self::assertSame(51, substr_count($inline, '<form'), 'before: one form per row + the add form');
        self::assertSame(51, substr_count($inline, '<dialog'));
        self::assertSame(0, substr_count($lazy, '<form'), 'no form on the page');
        self::assertSame(0, substr_count($lazy, '<textarea'));
        self::assertSame(0, substr_count($lazy, 'name="time_track_ledger'));
        self::assertSame(1, substr_count($lazy, '<dialog'), 'one empty remote dialog for every opener');
        self::assertStringContainsString('data-nowo-ui-remote-form', $lazy);
        self::assertSame(51, substr_count($lazy, 'data-nowo-ui-dialog-open="time-track-ledger-dialog"'));
        self::assertStringContainsString('href="/host/ledger/reverse?line=' . $lines[0]->getId() . '"', $lazy);
        self::assertStringContainsString('href="/host/ledger/add"', $lazy);
        self::assertLessThan(strlen($inline) / 2, strlen($lazy));

        // A `{line}` placeholder is replaced instead of a query parameter.
        $placeholder = $this->render('LedgerTable', $props + ['options' => ['dialog_urls' => ['reversal' => '/host/ledger/{line}/reverse#x']]]);
        self::assertStringContainsString('href="/host/ledger/' . $lines[0]->getId() . '/reverse#x"', $placeholder);
        self::assertStringNotContainsString('time-track-ledger-add', $placeholder, 'no add URL, no add action');

        // Owners never adjust their own balance: no opener, no dialog.
        $owner = $this->render('LedgerTable', ['viewer' => $this->user] + $props + ['options' => ['dialog_urls' => ['add_adjustment' => '/host/ledger/add', 'reversal' => '/host/ledger/reverse']]]);
        self::assertStringNotContainsString('/host/ledger', $owner);
        self::assertStringNotContainsString('<dialog', $owner);
    }

    public function testTheLedgerDialogServesTheFragmentOrThePageAndSubmitsInPlace(): void
    {
        $uri = '/host/ledger/add';

        $page = $this->dialogs->ledger($this->push(Request::create($uri)), $this->approver, $this->user, 'org-a', '/host/ledger');
        self::assertSame(Response::HTTP_OK, $page->getStatusCode());
        self::assertStringContainsString('data-testid="time-track-dialog-page"', (string) $page->getContent(), 'without the header: the full page');
        self::assertStringContainsString('name="time_track_ledger[reason]"', (string) $page->getContent());
        self::assertStringContainsString('href="/host/ledger"', (string) $page->getContent(), 'Cancel links back');
        self::assertStringContainsString('X-Nowo-Dialog', (string) $page->headers->get('Vary'));

        $fragment = $this->dialogs->ledger($this->push($this->dialogRequest($uri)), $this->approver, $this->user, 'org-a', '/host/ledger');
        $html     = (string) $fragment->getContent();
        self::assertSame(Response::HTTP_OK, $fragment->getStatusCode());
        self::assertStringNotContainsString('time-track-dialog-page', $html, 'with the header: the fragment only');
        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('action="/host/ledger/add"', $html);
        self::assertStringContainsString('data-nowo-ui-dialog-actions', $html);
        self::assertStringContainsString('data-nowo-ui-dialog-close', $html);
        self::assertMatchesRegularExpression('/name="time_track_ledger\[kind\]"[^>]*autofocus/', $html, 'the first field takes the focus');

        // 422: the same fragment again, with the typed values.
        $invalid = $this->dialogs->ledger($this->push($this->dialogRequest($uri, 'POST', ['time_track_ledger' => ['kind' => 'adjustment', 'minutes' => 'abc', 'effective_date' => '2026-10-02', 'reason' => 'Typed reason', '_token' => $this->token(LedgerLineType::CSRF_TOKEN_ID)]])), $this->approver, $this->user, 'org-a', '/host/ledger');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getStatusCode());
        self::assertStringContainsString('Typed reason', (string) $invalid->getContent());
        self::assertStringNotContainsString('time-track-dialog-page', (string) $invalid->getContent());

        // A ledger rule refusing the values (no reason) is a 422 in place with its message.
        $refused = $this->dialogs->ledger($this->push($this->dialogRequest($uri, 'POST', ['time_track_ledger' => ['kind' => 'adjustment', 'minutes' => '30', 'effective_date' => '2026-10-02', 'reason' => ' ', '_token' => $this->token(LedgerLineType::CSRF_TOKEN_ID)]])), $this->approver, $this->user, 'org-a', '/host/ledger');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused->getStatusCode());
        self::assertStringContainsString('time-track-dialog-error', (string) $refused->getContent());

        // A bad CSRF token: nothing written, the form again.
        $csrf = $this->dialogs->ledger($this->push($this->dialogRequest($uri, 'POST', ['time_track_ledger' => ['kind' => 'adjustment', 'minutes' => '30', 'effective_date' => '2026-10-02', 'reason' => 'Doctor visit', '_token' => 'nope']])), $this->approver, $this->user, 'org-a', '/host/ledger');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $csrf->getStatusCode());
        self::assertSame([], $this->ledger->linesOf((string) $this->user->getId(), 'org-a'));

        // 204 + X-Nowo-Redirect once written (dialog), a 303 to the same place without the header.
        $saved = $this->dialogs->ledger($this->push($this->dialogRequest($uri, 'POST', $this->adjustment('Doctor visit'))), $this->approver, $this->user, 'org-a', '/host/ledger', null, ['flash' => 'Saved.']);
        self::assertSame(Response::HTTP_NO_CONTENT, $saved->getStatusCode());
        self::assertSame('/host/ledger', $saved->headers->get('X-Nowo-Redirect'));
        self::assertSame(['Saved.'], $this->session->getFlashBag()->get('success'));
        $plain = $this->dialogs->ledger($this->push(Request::create($uri, 'POST', $this->adjustment('Second visit'))), $this->approver, $this->user, 'org-a', '/host/ledger');
        self::assertSame(Response::HTTP_SEE_OTHER, $plain->getStatusCode());
        self::assertSame('/host/ledger', $plain->headers->get('Location'));
        self::assertCount(2, $this->ledger->linesOf((string) $this->user->getId(), 'org-a'));
    }

    public function testTheReversalDialogRefusesExactlyAsThePost(): void
    {
        $line = $this->ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, -1800, '2026-10-02', 'Doctor visit');
        $uri  = '/host/ledger/reverse?line=' . $line->getId();

        $fragment = $this->dialogs->ledger($this->push($this->dialogRequest($uri)), $this->approver, $this->user, 'org-a', '/host/ledger', $line->getId());
        self::assertSame(Response::HTTP_OK, $fragment->getStatusCode());
        self::assertStringContainsString('value="' . $line->getId() . '"', (string) $fragment->getContent());

        // The owner (no self-adjustment) and a stranger: 403 on the dialog as on the post.
        foreach ([$this->user, $this->createUser('stranger@example.com')] as $actor) {
            foreach (['GET', 'POST'] as $method) {
                try {
                    $this->dialogs->ledger($this->push($this->dialogRequest($uri, $method, $this->reversal($line))), $actor, $this->user, 'org-a', '/host/ledger', $line->getId());
                    self::fail($method . ' as ' . $actor->getUserIdentifier());
                } catch (AccessDeniedHttpException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        // An unknown line, or a line of another scope: 404.
        $this->expectNotFound(fn (): Response => $this->dialogs->ledger($this->push($this->dialogRequest('/host/ledger/reverse?line=nope')), $this->approver, $this->user, 'org-a', '/host/ledger', 'nope'));

        $reversed = $this->dialogs->ledger($this->push($this->dialogRequest($uri, 'POST', $this->reversal($line))), $this->approver, $this->user, 'org-a', '/host/ledger', $line->getId());
        self::assertSame(Response::HTTP_NO_CONTENT, $reversed->getStatusCode());

        // REQ-RUNTIME-003: a second reversal (a concurrent one lost) is a 409, the dialog stays open with the message.
        $again = $this->dialogs->ledger($this->push($this->dialogRequest($uri, 'POST', $this->reversal($line))), $this->approver, $this->user, 'org-a', '/host/ledger', $line->getId());
        self::assertSame(Response::HTTP_CONFLICT, $again->getStatusCode());
        self::assertStringContainsString('This movement is already reversed.', (string) $again->getContent());
        self::assertStringContainsString('Second try', (string) $again->getContent(), 'the typed values stay');
        $opened = $this->dialogs->ledger($this->push($this->dialogRequest($uri)), $this->approver, $this->user, 'org-a', '/host/ledger', $line->getId());
        self::assertSame(Response::HTTP_CONFLICT, $opened->getStatusCode());
        $lines = $this->ledger->linesOf((string) $this->user->getId(), 'org-a');
        self::assertCount(2, $lines);
        self::assertSame($line->getId(), $lines[1]->getReversesId());
    }

    public function testTheWeekGridRejectDialogIsLoadedOnDemand(): void
    {
        $sheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $sheets);
        $week      = $sheets->period($this->user, 'org-a', 'previous');
        $submitted = $sheets->submit($this->user, 'org-a', $week);
        $urls      = ['approve' => '/host/approve', 'reject' => '/host/reject'];

        $inline = $this->render('TimesheetWeekGrid', ['timesheet' => $submitted, 'viewer' => $this->approver, 'options' => ['action_urls' => $urls]]);
        $lazy   = $this->render('TimesheetWeekGrid', ['timesheet' => $submitted, 'viewer' => $this->approver, 'options' => ['action_urls' => $urls, 'dialog_urls' => ['reject' => '/host/reject/dialog']]]);
        self::assertStringContainsString('name="time_track_reject[comment]"', $inline, 'before: the reject form on the page');
        self::assertStringNotContainsString('time_track_reject', $lazy);
        self::assertStringNotContainsString('<textarea', $lazy);
        self::assertStringContainsString('href="/host/reject/dialog?timesheet=' . $submitted->getId() . '"', $lazy);
        self::assertStringContainsString('data-nowo-ui-dialog-open="time-track-week-dialog-' . $submitted->getId() . '"', $lazy);
        self::assertStringContainsString('/host/approve', $lazy, 'approve stays a one-button form (no dialog)');
        self::assertSame(1, substr_count($lazy, '<dialog'));

        $uri      = '/host/reject/dialog?timesheet=' . $submitted->getId();
        $fragment = $this->dialogs->decision($this->push($this->dialogRequest($uri)), $this->approver, 'reject', $submitted->getId(), '/host/review');
        self::assertSame(Response::HTTP_OK, $fragment->getStatusCode());
        self::assertMatchesRegularExpression('/name="time_track_reject\[comment\]"[^>]*autofocus/', (string) $fragment->getContent());
        self::assertStringContainsString('value="' . $submitted->getId() . '"', (string) $fragment->getContent());

        // The owner may not reject: not_found, as reject() answers.
        $this->expectNotFound(fn (): Response => $this->dialogs->decision($this->push($this->dialogRequest($uri)), $this->user, 'reject', $submitted->getId(), '/host/review'));
        $this->expectNotFound(fn (): Response => $this->dialogs->decision($this->push($this->dialogRequest($uri, 'POST', $this->decision($submitted, 'Not enough detail here.'))), $this->user, 'reject', $submitted->getId(), '/host/review'));

        // A short comment: 422 in place with the message; a valid one: 204 + X-Nowo-Redirect.
        $short = $this->dialogs->decision($this->push($this->dialogRequest($uri, 'POST', $this->decision($submitted, 'Too short'))), $this->approver, 'reject', $submitted->getId(), '/host/review');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $short->getStatusCode());
        self::assertStringContainsString('time-track-dialog-error', (string) $short->getContent());
        $saved = $this->dialogs->decision($this->push($this->dialogRequest($uri, 'POST', $this->decision($submitted, 'Please split the support hours.'))), $this->approver, 'reject', $submitted->getId(), '/host/review');
        self::assertSame(Response::HTTP_NO_CONTENT, $saved->getStatusCode());
        self::assertSame('/host/review', $saved->headers->get('X-Nowo-Redirect'));

        // Rejected now: opening the dialog again is a 409 (invalid_transition), as the post.
        $stale = $this->dialogs->decision($this->push($this->dialogRequest($uri)), $this->approver, 'reject', $submitted->getId(), '/host/review');
        self::assertSame(Response::HTTP_CONFLICT, $stale->getStatusCode());
        $this->expectNotFound(fn (): Response => $this->dialogs->decision($this->push($this->dialogRequest($uri)), $this->approver, 'submit', $submitted->getId(), '/host/review'), invalidRequest: true);
    }

    public function testTheDialogTargetsRefuseLikeTheirActions(): void
    {
        $sheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $sheets);
        $week      = $sheets->period($this->user, 'org-a', 'previous');
        $submitted = $sheets->submit($this->user, 'org-a', $week);
        self::assertSame($submitted->getId(), $sheets->decisionTarget($this->approver, $submitted->getId(), 'approve')->getId());
        foreach ([[$this->approver, 'reopen', 'invalid_transition'], [$this->user, 'reopen', 'not_found'], [$this->user, 'approve', 'not_found'], [$this->approver, 'withdraw', 'invalid_request']] as [$actor, $action, $code]) {
            try {
                $sheets->decisionTarget($actor, $submitted->getId(), $action);
                self::fail($action . ' ' . $code);
            } catch (TimesheetException $e) {
                self::assertSame($code, $e->errorCode());
            }
        }
        $approved = $sheets->approve($this->approver, $submitted->getId());
        self::assertSame($approved->getId(), $sheets->decisionTarget($this->approver, $approved->getId(), 'reopen')->getId());

        // A movement dated in the approved (locked) week: 409, the dialog stays open.
        $date   = $week->start;
        $locked = $this->dialogs->ledger($this->push($this->dialogRequest('/host/ledger/add', 'POST', ['time_track_ledger' => ['kind' => 'adjustment', 'minutes' => '30', 'effective_date' => $date, 'reason' => 'Late correction', '_token' => $this->token(LedgerLineType::CSRF_TOKEN_ID)]])), $this->approver, $this->user, 'org-a', '/host/ledger');
        self::assertSame(Response::HTTP_CONFLICT, $locked->getStatusCode());
        self::assertStringContainsString('This period is locked.', (string) $locked->getContent());

        // A reversal cannot be reversed: the dialog answers the ledger's refusal (422) without its form being posted.
        $line     = $this->ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, 600, '2026-10-02', 'Ten minutes');
        $reversal = $this->ledger->add($this->approver, $this->user, 'org-a', LedgerKind::Reversal, 0, '2026-10-02', 'Undo', $line->getId());
        $opened   = $this->dialogs->ledger($this->push($this->dialogRequest('/host/ledger/reverse?line=' . $reversal->getId())), $this->approver, $this->user, 'org-a', '/host/ledger', $reversal->getId());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $opened->getStatusCode());
        self::assertStringContainsString('A reversal cannot be reversed.', (string) $opened->getContent());

        // An unknown action posted: invalid_request, in place.
        $unknown = $this->dialogs->decision($this->push($this->dialogRequest('/host/x', 'POST', [])), $this->approver, 'nope', $approved->getId(), '/host/review');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $unknown->getStatusCode());
    }

    public function testWithoutWorkingTimeTwigOrTranslatorTheDialogsFailClosed(): void
    {
        $sheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $sheets);
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $handler = new TimesheetFormHandler($factory, $sheets);
        try {
            $handler->ledgerDialogForm($this->approver, (string) $this->user->getId(), 'org-a');
            self::fail('no ledger');
        } catch (LedgerRefusedException $e) {
            self::assertSame('forbidden', $e->errorCode());
        }
        try {
            $handler->submitLedger($this->push(Request::create('/host/ledger', 'POST')), $this->approver, $this->user, 'org-a');
            self::fail('no ledger');
        } catch (LedgerRefusedException $e) {
            self::assertSame('forbidden', $e->errorCode());
        }

        $forms = self::getContainer()->get(TimesheetFormHandler::class);
        self::assertInstanceOf(TimesheetFormHandler::class, $forms);
        $this->expectException(LogicException::class);
        (new TimesheetDialogResponder($forms, null, null))->ledger($this->push(Request::create('/host/ledger/add')), $this->approver, $this->user, 'org-a', '/host/ledger');
    }

    /**
     * @param callable(): Response $call
     */
    private function expectNotFound(callable $call, bool $invalidRequest = false): void
    {
        try {
            $response = $call();
            self::assertTrue($invalidRequest, 'not found expected');
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        } catch (NotFoundHttpException) {
            self::assertFalse($invalidRequest);
        }
    }

    private function push(Request $request): Request
    {
        $request->setSession($this->session);
        $this->stack->push($request);

        return $request;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function dialogRequest(string $uri, string $method = 'GET', array $parameters = []): Request
    {
        $request = Request::create($uri, $method, $method === 'POST' ? $parameters : []);
        $request->headers->set('X-Nowo-Dialog', '1');

        return $request;
    }

    private function token(string $id): string
    {
        $tokens = self::getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);

        return $tokens->getToken($id)->getValue();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function adjustment(string $reason): array
    {
        return ['time_track_ledger' => ['kind' => 'adjustment', 'minutes' => '30', 'effective_date' => '2026-10-02', 'reason' => $reason, '_token' => $this->token(LedgerLineType::CSRF_TOKEN_ID)]];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function reversal(BalanceLedgerLine $line): array
    {
        return ['time_track_ledger_reversal' => ['reverses' => $line->getId(), 'effective_date' => '2026-10-03', 'reason' => 'Second try', '_token' => $this->token(LedgerLineType::CSRF_TOKEN_ID)]];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function decision(Timesheet $timesheet, string $comment): array
    {
        return ['time_track_reject' => ['timesheet' => $timesheet->getId(), 'scope' => '', 'period' => '', 'comment' => $comment, '_token' => $this->token(TimesheetDecisionType::CSRF_TOKEN_ID)]];
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $name, array $props): string
    {
        return $this->twig->createTemplate('{{ nowo_time_track_component(name, props) }}')->render(['name' => $name, 'props' => $props]);
    }
}
