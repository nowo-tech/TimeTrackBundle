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

use function in_array;
use function is_numeric;
use function is_string;

/**
 * Since 1.13: the web side of the timesheet components (contract §16a). Hosts build the forms the components render
 * and, in their own controllers (their routes, layout and permission catalogue), hand the posted request to this
 * handler: it validates the form (CSRF included) and runs the action through {@see TimesheetService} or
 * {@see BalanceLedger}, which apply the guard. Approve / reject / reopen and the ledger are web only (contract Q-O2).
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

        return $this->forms->createNamed('time_track_' . $action, TimesheetDecisionType::class, ['timesheet' => $timesheet?->getId()], $options);
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
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new TimesheetException('invalid_request');
        }
        /** @var array{timesheet?: mixed, comment?: mixed} $data */
        $data    = $form->getData();
        $id      = is_string($data['timesheet'] ?? null) ? $data['timesheet'] : '';
        $comment = is_string($data['comment'] ?? null) ? $data['comment'] : '';

        return match ($action) {
            'approve' => $this->timesheets->approve($actor, $id),
            'reject'  => $this->timesheets->reject($actor, $id, $comment),
            'reopen'  => $this->timesheets->reopen($actor, $id, $comment),
            default   => $this->ownerAction($actor, $id, $action === 'submit'),
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
        if (!$form->isSubmitted() || !$form->isValid()) {
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
        $kind = LedgerKind::from(is_string($data['kind'] ?? null) ? $data['kind'] : '');

        // @igor-ignore - I/O delegate (ledger append); no worker state.
        return $this->ledger->add($actor, $owner, $scopeKey, $kind, is_numeric($data['minutes'] ?? null) ? (int) $data['minutes'] * 60 : 0, $date, $reason);
    }

    private function ownerAction(UserInterface $owner, string $timesheetId, bool $submit): Timesheet
    {
        $timesheet = $this->timesheets->find($owner, $timesheetId);
        $period    = $timesheet->getPeriod();

        return $submit
            ? $this->timesheets->submit($owner, $timesheet->getScopeKey(), $period, AuditClient::Web)
            : $this->timesheets->withdraw($owner, $timesheet->getScopeKey(), $period, AuditClient::Web);
    }
}
