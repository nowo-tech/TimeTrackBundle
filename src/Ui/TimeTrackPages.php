<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Ui;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Dto\TimeEntryPage;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\PageStatus;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Form\FormView;
use Twig\Environment;
use Twig\Error\Error as TwigError;

use function in_array;
use function sprintf;
use function trigger_deprecation;

/**
 * View models of the manage UI pages (REQ-UI-003) and their layout.
 *
 * Both pages extend the UiKit list scaffold (`@NowoUiKit/page/list.html.twig`): the timer page lists the trackable
 * tasks (row action Start, header action Stop and a "Running" status while a timer runs), the reports page lists the
 * time entries of the period with the UiKit pagination. Labels are `NowoTimeTrackBundle` translation keys.
 *
 * Layout: `web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`). A 1.x layout that does not render the UiKit
 * canonical blocks (no `nowo_ui_page_header`) is wrapped in `_legacy_layout.html.twig` (deprecated since 1.8); the
 * 1.x default `@NowoTimeTrackBundle/layout.html.twig` maps to null.
 */
final readonly class TimeTrackPages
{
    /** Bundle name of the theme exceptions (`nowo_ui_kit.theme.bundles.NowoTimeTrackBundle`). */
    public const BUNDLE = 'NowoTimeTrackBundle';

    /** 1.x default of `templates.layout`: maps to the UiKit admin layout. */
    public const LEGACY_DEFAULT_LAYOUT = '@NowoTimeTrackBundle/layout.html.twig';

    /** Bridge between a 1.x host layout and the scaffolds (deprecated, removed in 2.0). */
    public const LEGACY_LAYOUT_BRIDGE = '@NowoTimeTrackBundle/_legacy_layout.html.twig';

    /** UiKit canonical blocks a host layout may render itself. */
    public const CANONICAL_BLOCKS = ['nowo_ui_document_title', 'nowo_ui_head', 'nowo_ui_styles', 'nowo_ui_page_header', 'nowo_ui_flashes', 'nowo_ui_content', 'nowo_ui_modals', 'nowo_ui_scripts'];

    /**
     * @param string|null $layoutTemplate `web_ui.layout_template` (null = UiKit admin layout)
     * @param Environment|null $twig Inspects a configured layout for the canonical blocks
     */
    public function __construct(
        private ?string $layoutTemplate = null,
        private ?Environment $twig = null,
    ) {
    }

    /**
     * Timer page: trackable tasks with a Start action each (none while a timer runs), Stop in the header.
     *
     * @param list<TaskReference> $tasks Trackable tasks
     * @param ActiveTimer|null $active Running timer of the user
     * @param FormView $stopForm CSRF form of the Stop action
     * @param array<string, FormView> $startForms CSRF form of the Start action per task id
     *
     * @return ListPage The page view model
     */
    public function timerPage(array $tasks, ?ActiveTimer $active, FormView $stopForm, array $startForms): ListPage
    {
        $header = $active instanceof ActiveTimer
            ? new PageHeader(
                self::label('page.timer'),
                self::label('timer.running_intro', [
                    '%task%'    => $active->getTaskTitleSnapshot(),
                    '%started%' => $active->getStartedAt()->format('Y-m-d H:i'),
                ]),
                [new Action(kind: 'custom', label: self::label('action.stop'), method: 'POST', variant: 'danger', attributes: ['data-testid' => 'time-track-stop'], form: $stopForm)],
                status: new PageStatus(self::label('timer.status_running'), 'success'),
            )
            : new PageHeader(self::label('page.timer'), self::label('timer.none'));

        return new ListPage(
            $header,
            [new Column('title', self::label('tasks.task'), required: true), new Column('boardTitle', self::label('tasks.board'), priority: 2)],
            $tasks,
            rowActions: static fn (TaskReference $task): array => $active instanceof ActiveTimer || !isset($startForms[$task->id]) ? [] : [
                new Action(kind: 'custom', label: self::label('action.start'), method: 'POST', variant: 'primary', attributes: ['data-testid' => 'time-track-start-' . $task->id], form: $startForms[$task->id], display: 'text'),
            ],
            empty: new EmptyState(self::label('tasks.empty')),
            caption: self::label('tasks.title'),
            bundle: self::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'time-track-timer'],
            actionsLabel: self::label('tasks.actions'),
        );
    }

    /**
     * Reports page: the entries of the period, newest first, with the UiKit pagination.
     *
     * @param TimeEntryPage $entries Entries of the current page and totals
     * @param DateTimeImmutable $from Period start
     * @param DateTimeImmutable $to Period end
     * @param string|null $route Route of the pagination links (the current route)
     * @param array<string, mixed> $query Query kept across pages (`from`, `to`)
     *
     * @return ListPage The page view model
     */
    public function reportsPage(TimeEntryPage $entries, DateTimeImmutable $from, DateTimeImmutable $to, ?string $route, array $query = []): ListPage
    {
        return new ListPage(
            new PageHeader(self::label('page.reports'), self::label('reports.period', ['%from%' => $from->format('Y-m-d'), '%to%' => $to->format('Y-m-d')])),
            [
                new Column('taskTitleSnapshot', self::label('reports.task'), required: true),
                new Column('startedAt', self::label('reports.started')),
                new Column('durationSeconds', self::label('reports.duration'), align: 'end'),
                new Column('source', self::label('reports.source'), priority: 2),
            ],
            $entries->entries,
            pagination: new Pagination($entries->page, $entries->perPage, $entries->total, $entries->totalPages(), $route, $query),
            empty: new EmptyState(self::label('reports.empty')),
            caption: self::label('page.reports'),
            bundle: self::BUNDLE,
            layout: $this->layout(),
            attributes: ['data-testid' => 'time-track-reports'],
        );
    }

    /**
     * Layout of the pages: null (UiKit admin layout), the configured layout, or the 1.x bridge.
     */
    public function layout(): ?string
    {
        return $this->resolve()[0];
    }

    /**
     * Render context for the 1.x bridge (empty when no bridge is used).
     *
     * @return array<string, mixed>
     */
    public function layoutContext(): array
    {
        [$layout, $legacy, $blocks] = $this->resolve();

        return $layout === self::LEGACY_LAYOUT_BRIDGE
            ? ['nowo_time_track_legacy_layout' => $legacy, 'nowo_time_track_legacy_blocks' => $blocks]
            : [];
    }

    /**
     * Resolved [page layout, 1.x host layout, canonical blocks it renders]; not memoized (no state in the service,
     * worker safe) — Twig caches the compiled layout.
     *
     * @return array{0: string|null, 1: string|null, 2: list<string>}
     */
    private function resolve(): array
    {
        $layout = $this->layoutTemplate;
        if (in_array($layout, [null, '', self::LEGACY_DEFAULT_LAYOUT], true)) {
            return [null, null, []];
        }

        $blocks = $this->renderedCanonicalBlocks($layout);
        if ($blocks === null || in_array('nowo_ui_page_header', $blocks, true)) {
            return [$layout, null, []];
        }

        trigger_deprecation('nowo-tech/time-track-bundle', '1.8', sprintf('The TimeTrack layout "%s" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped in a bridge that is removed in 2.0. Render the canonical blocks or set nowo_ui_kit.layouts.admin instead (see docs/UPGRADING.md).', $layout));

        return [self::LEGACY_LAYOUT_BRIDGE, $layout, $blocks];
    }

    /**
     * Canonical blocks the layout defines (null when it cannot be inspected: no Twig, or a template error).
     *
     * @return list<string>|null
     */
    private function renderedCanonicalBlocks(string $layout): ?array
    {
        if (!$this->twig instanceof Environment) {
            return null;
        }

        try {
            $template = $this->twig->load($layout);
        } catch (TwigError) {
            return null;
        }

        $blocks = [];
        foreach (self::CANONICAL_BLOCKS as $block) {
            if ($template->hasBlock($block)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, float|int|string|null> $params
     */
    private static function label(string $key, array $params = []): Label
    {
        return new Label($key, TimeTrackBundle::TRANSLATION_DOMAIN, $params);
    }
}
