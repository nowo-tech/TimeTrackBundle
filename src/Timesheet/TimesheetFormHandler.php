<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Form\LedgerLineType;
use Nowo\TimeTrackBundle\Form\TimesheetDecisionType;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function abs;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * Since 1.13: the web side of the timesheet components (contract §16a). Hosts build the forms the components render
 * and, in their own controllers (their routes, layout and permission catalogue), hand the posted request to this
 * handler: it validates the form and runs the action through {@see TimesheetService} or {@see BalanceLedger}, which
 * apply the guard. Approve / reject / reopen and the ledger are web only (contract Q-O2).
 *
 * CSRF (audit GB-L2): the handler checks the posted `_token` itself with the CSRF token manager, whatever the host's
 * `framework.form.csrf_protection` says, and refuses every post (`invalid_request`) when no token manager exists.
 * Owner actions (submit / withdraw) act on the actor's own period named by the posted `scope` + `period` (a period
 * without a stored row included); a posted timesheet id of someone else is `not_found` (GB-I3).
 */
final readonly class TimesheetFormHandler
{
    public const ACTIONS = ['submit', 'withdraw', 'approve', 'reject', 'reopen'];

    /** Actions that need a comment. */
    public const COMMENT_ACTIONS = ['reject', 'reopen'];

    /**
     * Receives the form factory and the services.
     */
    public function __construct(
        private FormFactoryInterface $forms,
        private TimesheetService $timesheets,
        private ?BalanceLedger $ledger = null,
        private ?CsrfTokenManagerInterface $csrfTokens = null,
    ) {
    }

    /**
     * The form of a timesheet action (posted to `$actionUrl`).
     *
     * @return FormInterface<mixed>
     */
    public function decisionForm(string $action, ?Timesheet $timesheet = null, ?string $actionUrl = null): FormInterface
    {
        $options = ['with_comment' => in_array($action, self::COMMENT_ACTIONS, true)];
        if ($actionUrl !== null) {
            $options['action'] = $actionUrl;
        }

        $data = ['timesheet' => $timesheet instanceof Timesheet && !$timesheet->isDraft() ? $timesheet->getId() : null, 'scope' => $timesheet?->getScopeKey(), 'period' => $timesheet?->getPeriod()->key];

        return $this->forms->createNamed('time_track_' . $action, TimesheetDecisionType::class, $data, $options);
    }

    /**
     * Runs a posted timesheet action.
     *
     * @throws TimesheetException `invalid_request` for an unknown action or an invalid form; the service's codes otherwise
     */
    public function handleDecision(Request $request, UserInterface $actor, string $action): Timesheet
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new TimesheetException('invalid_request');
        }
        $form = $this->decisionForm($action);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid() || !$this->validCsrf($request, $form, TimesheetDecisionType::CSRF_TOKEN_ID)) {
            throw new TimesheetException('invalid_request');
        }
        /** @var array{timesheet?: mixed, comment?: mixed, scope?: mixed, period?: mixed} $data */
        $data    = $form->getData();
        $id      = is_string($data['timesheet'] ?? null) ? $data['timesheet'] : '';
        $comment = is_string($data['comment'] ?? null) ? $data['comment'] : '';

        return match ($action) {
            'approve' => $this->timesheets->approve($actor, $id),
            'reject'  => $this->timesheets->reject($actor, $id, $comment),
            'reopen'  => $this->timesheets->reopen($actor, $id, $comment),
            default   => $this->ownerAction($actor, $id, is_string($data['scope'] ?? null) ? $data['scope'] : '', is_string($data['period'] ?? null) ? $data['period'] : '', $action === 'submit'),
        };
    }

    /**
     * The form of a ledger line (an adjustment, or with `$reverses` the reversal of that line).
     *
     * @return FormInterface<mixed>
     */
    public function ledgerForm(?string $actionUrl = null, ?string $reverses = null): FormInterface
    {
        $options = ['reversal' => $reverses !== null];
        if ($actionUrl !== null) {
            $options['action'] = $actionUrl;
        }

        return $this->forms->createNamed($reverses === null ? 'time_track_ledger' : 'time_track_ledger_reversal', LedgerLineType::class, $reverses === null ? null : ['reverses' => $reverses], $options);
    }

    /**
     * Adds the posted ledger line for `$owner` in the scope.
     *
     * @throws LedgerRefusedException `invalid_request` for an invalid form, `forbidden` without working time; the ledger's codes otherwise
     */
    public function handleLedger(Request $request, UserInterface $actor, UserInterface $owner, string $scopeKey, bool $reversal = false): BalanceLedgerLine
    {
        if (!$this->ledger instanceof BalanceLedger) {
            throw new LedgerRefusedException('forbidden');
        }
        $form = $this->ledgerForm(null, $reversal ? '' : null);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid() || !$this->validCsrf($request, $form, LedgerLineType::CSRF_TOKEN_ID)) {
            throw new LedgerRefusedException('invalid_request');
        }
        /** @var array<string, mixed> $data */
        $data   = $form->getData();
        $date   = is_string($data['effective_date'] ?? null) ? $data['effective_date'] : '';
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : null;
        if ($reversal) {
            // @igor-ignore - I/O delegate (ledger append); no worker state.
            return $this->ledger->add($actor, $owner, $scopeKey, LedgerKind::Reversal, 0, $date, $reason, is_string($data['reverses'] ?? null) ? $data['reverses'] : '');
        }
        // The choice field only accepts LedgerLineType::MANUAL_KINDS.
        $kind    = LedgerKind::from(is_string($data['kind'] ?? null) ? $data['kind'] : '');
        $minutes = is_numeric($data['minutes'] ?? null) ? (int) $data['minutes'] : 0;
        // GB-I9: bounded before the multiplication (no integer overflow into a float).
        if (abs($minutes) > LedgerLineType::MAX_MINUTES) {
            throw new LedgerRefusedException('invalid_seconds');
        }

        // @igor-ignore - I/O delegate (ledger append); no worker state.
        return $this->ledger->add($actor, $owner, $scopeKey, $kind, $minutes * 60, $date, $reason);
    }

    /**
     * Submit / withdraw of the actor's own period: by the posted scope and period key, else by the posted id of one of
     * the actor's own timesheets.
     */
    private function ownerAction(UserInterface $owner, string $timesheetId, string $scopeKey, string $periodKey, bool $submit): Timesheet
    {
        if ($scopeKey === '' || $periodKey === '') {
            $timesheet = $this->timesheets->find($owner, $timesheetId);
            if (!$this->timesheets->accessGuard()->isOwner($owner, $timesheet->getOwnerRef())) {
                throw new TimesheetException('not_found');
            }
            $scopeKey  = $timesheet->getScopeKey();
            $periodKey = $timesheet->getPeriod()->key;
        }
        $period = $this->timesheets->period($owner, $scopeKey, $periodKey);

        return $submit
            ? $this->timesheets->submit($owner, $scopeKey, $period, AuditClient::Web)
            : $this->timesheets->withdraw($owner, $scopeKey, $period, AuditClient::Web);
    }

    /**
     * Whether the posted `_token` of the form is valid for the token id (false without a token manager).
     *
     * @param FormInterface<mixed> $form
     */
    private function validCsrf(Request $request, FormInterface $form, string $tokenId): bool
    {
        $posted = $request->request->all()[$form->getName()] ?? null;
        $token  = is_array($posted) ? ($posted['_token'] ?? null) : null;

        return $this->csrfTokens instanceof CsrfTokenManagerInterface && is_string($token) && $token !== '' && $this->csrfTokens->isTokenValid(new CsrfToken($tokenId, $token));
    }
}
