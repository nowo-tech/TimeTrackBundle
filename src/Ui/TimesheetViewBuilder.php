<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Ui;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormHandler;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\WorkingTime\BalanceReport;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_combine;
use function array_filter;
use function array_intersect;
use function array_values;
use function count;
use function explode;
use function in_array;
use function is_string;
use function rawurlencode;
use function sprintf;
use function str_contains;
use function str_replace;
use function trigger_deprecation;

/**
 * Since 1.13: view models of the configurable Twig components (contract §16a; hosts compose their pages from them, no
 * template overrides): `TimeTrack:TimesheetWeekGrid`, `TimeTrack:BalanceCard`, `TimeTrack:LedgerTable`. Options:
 * labels (translation keys or text per slot), visible actions with the host URLs they post to, density
 * (`comfortable` / `compact`), rows and columns. Actions are offered only when the guard allows them for the viewer; the
 * server checks again on post. Approvers see task titles masked when they cannot see the task, and notes only with
 * `timesheets.approver_sees_notes`. Imported entries whose task the owner can no longer see are masked for every viewer,
 * the owner included (the 1.11 rule of the client API; audit GB-M2). The grid and the ledger table check that the
 * viewer may see the owner's data in that scope (`not_found` otherwise; audit GB-I2), whatever the host passes in.
 */
final readonly class TimesheetViewBuilder
{
    public const DENSITIES = ['comfortable', 'compact'];

    /** Since 1.38.0: the week-grid actions a dialog URL (`options.dialog_urls`) can serve. */
    public const DIALOG_ACTIONS = ['approve', 'reject', 'reopen'];

    /**
     * @param bool $approverSeesNotes `timesheets.approver_sees_notes`
     */
    public function __construct(
        private TimesheetService $timesheets,
        private TimesheetFormHandler $forms,
        private ?TaskAccessCheckerInterface $taskAccess = null,
        private bool $approverSeesNotes = false,
        private ?TimerService $timerService = null,
    ) {
    }

    /**
     * The week (or month) grid of a timesheet for a viewer (the owner or an authorised approver).
     *
     * @param array<string, mixed> $options labels, actions, action_urls, density, show_expected, show_diff, read_only
     *
     * @throws TimesheetException `not_found` when the viewer may not see the timesheet
     *
     * @return array<string, mixed>
     */
    public function weekGrid(Timesheet $timesheet, UserInterface $viewer, array $options = []): array
    {
        if (!$this->timesheets->accessGuard()->canView($viewer, $timesheet->getOwnerRef(), $timesheet->getScopeKey())) {
            throw new TimesheetException('not_found');
        }
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['labels' => [], 'actions' => TimesheetFormHandler::ACTIONS, 'action_urls' => [], 'dialog_urls' => [], 'density' => 'comfortable', 'show_expected' => true, 'show_diff' => true, 'read_only' => null]);
        $resolver->setAllowedValues('density', self::DENSITIES);
        $resolver->setAllowedTypes('labels', 'array');
        $resolver->setAllowedTypes('actions', 'array');
        $resolver->setAllowedTypes('action_urls', 'array');
        $resolver->setAllowedTypes('dialog_urls', 'array');
        $resolver->setAllowedTypes('read_only', ['null', 'bool']);
        /** @var array{labels: array<string, string>, actions: list<string>, action_urls: array<string, string>, dialog_urls: array<string, string>, density: string, show_expected: bool, show_diff: bool, read_only: bool|null} $options */
        $options = $resolver->resolve($options);

        $owner    = UserIdResolver::getId($viewer) === $timesheet->getOwnerRef();
        $snapshot = $this->timesheets->snapshot($timesheet);
        $period   = $timesheet->getPeriod();
        $dates    = $period->dates();
        $notes    = $owner || $this->approverSeesNotes;
        $hidden   = $this->timerService?->hiddenTaskEntryIds($snapshot->entries) ?? [];
        $rows     = [];
        $daily    = array_combine($dates, array_fill(0, count($dates), 0));
        foreach ($snapshot->entries as $entry) {
            // Since 1.36.0: entries without a task share one "No task" row (task id '').
            $taskId = $entry->getTaskId();
            $date   = StorageTime::localDate($entry->getStartedAt(), $timesheet->getTimezone());
            $rows[$taskId] ??= ['taskId' => $taskId, 'title' => isset($hidden[$entry->getId()]) ? null : $this->title($entry, $viewer, $owner), 'masked' => false, 'imported' => !$entry->getSource()->isLive(), 'days' => array_combine($dates, array_fill(0, count($dates), 0)), 'total' => 0, 'notes' => [], 'withoutTask' => !$entry->hasTask()];
            $rows[$taskId]['masked'] = $rows[$taskId]['title'] === null;
            $rows[$taskId]['days'][$date] += $entry->getDurationSeconds();
            $rows[$taskId]['total'] += $entry->getDurationSeconds();
            $daily[$date] += $entry->getDurationSeconds();
            $note = ($entry->getMetadata() ?? [])['note'] ?? null;
            if ($notes && !isset($hidden[$entry->getId()]) && is_string($note) && $note !== '') {
                $rows[$taskId]['notes'][] = $note;
            }
            // Since 1.36.0: the free text of an entry without a task is shown like a note (same visibility).
            $description = $entry->hasTask() ? null : $entry->getDescription();
            if ($notes && !isset($hidden[$entry->getId()]) && $description !== null && $description !== '') {
                $rows[$taskId]['notes'][] = $description;
            }
        }

        $report   = $snapshot->balance;
        $byDate   = $report instanceof BalanceReport ? array_combine(array_column($report->days, 'date'), $report->days) : [];
        $readOnly = $options['read_only'] ?? !$owner;
        $actions  = [];
        foreach (array_intersect($options['actions'], $this->allowedActions($timesheet, $viewer, $owner)) as $action) {
            $url       = $options['action_urls'][$action] ?? null;
            $dialogUrl = in_array($action, self::DIALOG_ACTIONS, true) ? ($options['dialog_urls'][$action] ?? null) : null;
            if (is_string($dialogUrl)) {
                // Since 1.38.0: a link to the host's dialog page; the form is built only when the dialog opens.
                $actions[$action] = ['form' => null, 'dialog' => true, 'url' => $this->withQuery($dialogUrl, 'timesheet', $timesheet->getId())];
            } elseif (is_string($url) && ($action === 'submit' || $action === 'withdraw' ? !$readOnly : true)) {
                $dialog = in_array($action, TimesheetFormHandler::COMMENT_ACTIONS, true);
                if ($dialog) {
                    $this->deprecateInlineDialog('weekGrid', $action);
                }
                $actions[$action] = ['form' => $this->forms->decisionForm($action, $timesheet, $url)->createView(), 'dialog' => $dialog, 'url' => null];
            }
        }
        $lazy = array_filter($actions, static fn (array $a): bool => $a['url'] !== null) !== [];

        return [
            'id'              => $timesheet->getId(),
            'owner'           => $owner,
            'period'          => $period,
            'timezone'        => $timesheet->getTimezone(),
            'status'          => $timesheet->getStatus()->value,
            'locked'          => $timesheet->isLocked(),
            'lockReason'      => $timesheet->getLockReason()?->value,
            'decisionComment' => $timesheet->getDecisionComment(),
            'dates'           => $dates,
            'rows'            => array_values($rows),
            'daily'           => $daily,
            'total'           => array_sum($daily),
            'expected'        => $options['show_expected'] && $byDate !== [] ? array_map(static fn (array $d): int => $d['expectedSeconds'], $byDate) : null,
            'diff'            => $options['show_diff'] && $byDate !== [] ? array_map(static fn (array $d): int => $d['diffSeconds'], $byDate) : null,
            'legalRecord'     => $report?->legalRecord,
            'unassigned'      => $owner ? count($snapshot->unassigned) : 0,
            'actions'         => $actions,
            'dialogId'        => $lazy ? 'time-track-week-dialog-' . $timesheet->getId() : null,
            'labels'          => $options['labels'],
            'density'         => $options['density'],
            'readOnly'        => $readOnly,
        ];
    }

    /**
     * The balance card of a report.
     *
     * @param array<string, mixed> $options labels, range (`week` / `month` / `year` / `custom`), show_overtime, legal_badge (label key or text)
     *
     * @return array<string, mixed>
     */
    public function balanceCard(BalanceReport $report, array $options = []): array
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['labels' => [], 'range' => 'custom', 'show_overtime' => true, 'legal_badge' => 'balance.legal_notice', 'density' => 'comfortable']);
        $resolver->setAllowedValues('range', ['week', 'month', 'year', 'custom']);
        $resolver->setAllowedValues('density', self::DENSITIES);
        $resolver->setAllowedTypes('labels', 'array');
        $resolver->setAllowedTypes('legal_badge', 'string');
        /** @var array{labels: array<string, string>, range: string, show_overtime: bool, legal_badge: string, density: string} $options */
        $options = $resolver->resolve($options);

        return [
            'from'        => $report->from,
            'to'          => $report->to,
            'range'       => $options['range'],
            'totals'      => $report->totals,
            'balance'     => $report->balanceSeconds,
            'source'      => $report->source,
            'legalRecord' => $report->legalRecord,
            'legalBadge'  => $report->legalRecord ? null : $options['legal_badge'],
            'overtime'    => $options['show_overtime'] ? $report->overtime : null,
            'policy'      => $report->policy,
            'labels'      => $options['labels'],
            'density'     => $options['density'],
        ];
    }

    /**
     * The ledger table of an owner and scope; the add and reversal actions only for viewers allowed to adjust.
     *
     * @param list<BalanceLedgerLine> $lines
     * @param array<string, mixed> $options labels, columns, actions (`add_adjustment`, `reversal`), action_urls, density
     *
     * @throws TimesheetException `not_found` when the viewer may not see the owner's balance in the scope
     *
     * @return array<string, mixed>
     */
    public function ledgerTable(array $lines, UserInterface $viewer, string $ownerId, string $scopeKey, array $options = []): array
    {
        if (!$this->timesheets->accessGuard()->canView($viewer, $ownerId, $scopeKey)) {
            throw new TimesheetException('not_found');
        }
        $resolver = new OptionsResolver();
        $resolver->setDefaults(['labels' => [], 'columns' => ['effectiveDate', 'kind', 'seconds', 'reason', 'createdAt'], 'actions' => ['add_adjustment', 'reversal'], 'action_urls' => [], 'dialog_urls' => [], 'density' => 'comfortable']);
        $resolver->setAllowedValues('density', self::DENSITIES);
        $resolver->setAllowedTypes('labels', 'array');
        $resolver->setAllowedTypes('columns', 'array');
        $resolver->setAllowedTypes('actions', 'array');
        $resolver->setAllowedTypes('action_urls', 'array');
        $resolver->setAllowedTypes('dialog_urls', 'array');
        /** @var array{labels: array<string, string>, columns: list<string>, actions: list<string>, action_urls: array<string, string>, dialog_urls: array<string, string>, density: string} $options */
        $options = $resolver->resolve($options);

        $canAdjust = $this->timesheets->accessGuard()->canAdjustBalance($viewer, $ownerId, $scopeKey);
        $reversed  = [];
        $lines     = array_values(array_filter($lines, static fn (BalanceLedgerLine $line): bool => $line->getOwnerRef() === $ownerId && $line->getScopeKey() === $scopeKey));
        foreach ($lines as $line) {
            if ($line->getReversesId() !== null) {
                $reversed[$line->getReversesId()] = true;
            }
        }
        $reversalUrl    = $options['action_urls']['reversal'] ?? null;
        $reversalDialog = $options['dialog_urls']['reversal'] ?? null;
        $rows           = [];
        $inline         = false;
        foreach ($lines as $line) {
            $reversible = $canAdjust && in_array('reversal', $options['actions'], true) && (is_string($reversalDialog) || is_string($reversalUrl))
                && !isset($reversed[$line->getId()]) && $line->getKind() !== LedgerKind::Reversal && $line->getKind() !== LedgerKind::OvertimeRatified;
            // Since 1.38.0: with dialog_urls.reversal a link per row (the form is built when its dialog opens).
            $opener = $reversible && is_string($reversalDialog) ? $this->withQuery($reversalDialog, 'line', $line->getId()) : null;
            $form   = $reversible && $opener === null && is_string($reversalUrl) ? $this->forms->ledgerForm($reversalUrl, $line->getId())->createView() : null;
            $inline = $inline || $form instanceof FormView;
            $rows[] = $line->toArray() + ['reversed' => isset($reversed[$line->getId()]), 'reversalForm' => $form, 'reversalUrl' => $opener];
        }
        if ($inline) {
            $this->deprecateInlineDialog('ledgerTable', 'reversal');
        }
        $addAllowed = $canAdjust && in_array('add_adjustment', $options['actions'], true);
        $addDialog  = $options['dialog_urls']['add_adjustment'] ?? null;
        $addUrl     = $options['action_urls']['add_adjustment'] ?? null;
        $addOpener  = $addAllowed && is_string($addDialog) ? $addDialog : null;
        $addForm    = $addAllowed && $addOpener === null && is_string($addUrl) ? $this->forms->ledgerForm($addUrl)->createView() : null;
        if ($addForm instanceof FormView) {
            $this->deprecateInlineDialog('ledgerTable', 'add_adjustment');
        }
        $lazy = $addOpener !== null || array_filter($rows, static fn (array $row): bool => $row['reversalUrl'] !== null) !== [];

        return [
            'rows'     => $rows,
            'columns'  => $options['columns'],
            'addForm'  => $addForm,
            'addUrl'   => $addOpener,
            'dialogId' => $lazy ? 'time-track-ledger-dialog' : null,
            'labels'   => $options['labels'],
            'density'  => $options['density'],
        ];
    }

    /**
     * Since 1.38.0: `$url` with `{<name>}` replaced by the value, else with `<name>=<value>` added to its query.
     */
    private function withQuery(string $url, string $name, string $value): string
    {
        $placeholder = '{' . $name . '}';
        if (str_contains($url, $placeholder)) {
            return str_replace($placeholder, rawurlencode($value), $url);
        }
        [$path, $fragment] = str_contains($url, '#') ? explode('#', $url, 2) : [$url, null];

        return $path . (str_contains($path, '?') ? '&' : '?') . rawurlencode($name) . '=' . rawurlencode($value) . ($fragment === null ? '' : '#' . $fragment);
    }

    /**
     * Since 1.38.0: the inline dialog forms (a form and a dialog body per action, rendered whether opened or not)
     * break the on-demand dialog rule; 2.0 removes them.
     */
    private function deprecateInlineDialog(string $component, string $action): void
    {
        trigger_deprecation('nowo-tech/time-track-bundle', '1.38', sprintf('Rendering the "%s" dialog form of the TimeTrack %s inline (options.action_urls without options.dialog_urls.%s) is deprecated and 2.0 removes it: pass options.dialog_urls.%s, the URL of a page served by Nowo\\TimeTrackBundle\\Ui\\TimesheetDialogResponder, so the dialog loads its form on demand.', $action, $component, $action, $action));
    }

    /**
     * @return list<string>
     */
    private function allowedActions(Timesheet $timesheet, UserInterface $viewer, bool $owner): array
    {
        $status  = $timesheet->getStatus();
        $guard   = $this->timesheets->accessGuard();
        $ownerId = $timesheet->getOwnerRef();
        $scope   = $timesheet->getScopeKey();
        if ($owner) {
            return $status === TimesheetStatus::Submitted ? ['withdraw'] : ($status->isEditable() ? ['submit'] : []);
        }
        $actions = [];
        if ($status === TimesheetStatus::Submitted && $guard->canDecide($viewer, $ownerId, $scope)) {
            $actions = ['approve', 'reject'];
        }
        if ($timesheet->isLocked() && $guard->canReopen($viewer, $ownerId, $scope)) {
            $actions[] = 'reopen';
        }

        return $actions;
    }

    /**
     * The task title the viewer may see (null = masked: an approver who cannot see the task).
     */
    private function title(TimeEntry $entry, UserInterface $viewer, bool $owner): ?string
    {
        // Since 1.36.0: no task to mask an entry without one by (the template shows "No task").
        if (!$entry->hasTask() || $owner || !$this->taskAccess instanceof TaskAccessCheckerInterface || $this->taskAccess->canTrack($viewer, $entry->getTaskId())) {
            return $entry->getTaskTitleSnapshot();
        }

        return null;
    }
}
