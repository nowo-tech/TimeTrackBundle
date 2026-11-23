<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Symfony\Component\Form\FormInterface;

/**
 * Since 1.38.0: the outcome of a posted timesheet / ledger form ({@see TimesheetFormHandler::submitDecision()} /
 * {@see TimesheetFormHandler::submitLedger()}): the written timesheet or ledger line, or nothing when the form was not
 * valid. The form is kept either way, so a remote dialog re-renders it in place (422) with its errors and typed values.
 * `csrfFailed`: the form was valid but its token was missing, invalid or expired (nothing ran).
 */
final readonly class TimesheetFormResult
{
    /**
     * @param FormInterface<mixed> $form
     */
    public function __construct(
        public FormInterface $form,
        public ?Timesheet $timesheet = null,
        public ?BalanceLedgerLine $line = null,
        public bool $csrfFailed = false,
    ) {
    }

    /**
     * Whether the form was valid and the action ran.
     */
    public function isValid(): bool
    {
        return $this->timesheet instanceof Timesheet || $this->line instanceof BalanceLedgerLine;
    }
}
