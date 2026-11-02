<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Form\TimerActionType;
use Nowo\TimeTrackBundle\Security\TimeTrackAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use Nowo\TimeTrackBundle\Ui\TimeTrackPages;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;

/**
 * Manage UI: timer page (start / stop, CSRF-protected forms) and the reports list.
 */
final class TimeTrackManageController extends AbstractController
{
    /** Default page size of the reports list (`nowo_time_track.reports.page_size`, REQ-PERF-001). */
    public const DEFAULT_REPORTS_PAGE_SIZE = 50;

    /**
     * @param TimerService $timerService Timer use cases
     * @param TimeTrackAccessCheckerInterface $accessChecker Access contract of the manage UI (REQ-UI-002)
     * @param array<string, string> $templates Page templates (`templates.index`, `templates.reports`)
     * @param bool $allowUnauthenticated Demo-only bypass of the access checker
     * @param TranslatorInterface|null $translator Translates the flash messages
     * @param int $reportsPageSize Entries per reports page
     * @param ClockInterface|null $clock Reference time of the default report period
     * @param TimeTrackPages|null $pages View models of the pages (REQ-UI-003)
     */
    public function __construct(
        private readonly TimerService $timerService,
        private readonly TimeTrackAccessCheckerInterface $accessChecker,
        private readonly array $templates,
        private readonly bool $allowUnauthenticated = false,
        private readonly ?TranslatorInterface $translator = null,
        private readonly int $reportsPageSize = self::DEFAULT_REPORTS_PAGE_SIZE,
        private readonly ?ClockInterface $clock = null,
        private readonly ?TimeTrackPages $pages = null,
    ) {
    }

    /**
     * Timer page: active timer, trackable tasks and the start / stop forms; POST runs the action and redirects.
     */
    public function index(Request $request): Response
    {
        $this->denyUnlessGrantedAccess();

        $user   = $this->getUser();
        $active = $user instanceof UserInterface ? $this->timerService->getActive($user) : null;
        $tasks  = $user instanceof UserInterface ? $this->timerService->listTasks($user, new TaskListQuery(limit: 20)) : [];

        if ($request->isMethod('POST') && $user instanceof UserInterface) {
            $form = $this->createTimerActionForm([]);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            /** @var array{action?: string|null, task_id?: string|null} $data */
            $data   = $form->getData();
            $action = (string) ($data['action'] ?? '');
            $taskId = (string) ($data['task_id'] ?? '');

            if ($action === 'start' && $taskId !== '') {
                try {
                    $this->timerService->start($user, $taskId, ClientType::Web);
                    $this->addFlash('success', $this->trans('flash.timer_started'));
                } catch (ActiveTimerConflictException) {
                    $this->addFlash('warning', $this->trans('flash.timer_already_active'));
                } catch (InvalidArgumentException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }

            if ($action === 'stop') {
                if ($this->timerService->stop($user) instanceof TimeEntry) {
                    $this->addFlash('success', $this->trans('flash.timer_stopped'));
                } else {
                    $this->addFlash('warning', $this->trans('flash.no_active_timer'));
                }
            }

            return $this->redirectToRoute('nowo_time_track_index');
        }

        /** @var array<string, FormView> $startForms */
        $startForms = [];
        foreach ($tasks as $task) {
            $startForms[$task->id] = $this->createTimerActionForm(['action' => 'start', 'task_id' => $task->id], 'start_' . $task->id)->createView();
        }

        $stopForm = $this->createTimerActionForm(['action' => 'stop'], 'stop')->createView();
        $pages    = $this->pages ?? new TimeTrackPages();

        return $this->render($this->templates['index'], [
            'page'         => $pages->timerPage($tasks, $active, $stopForm, $startForms),
            'active_timer' => $active,
            'tasks'        => $tasks,
            'stop_form'    => $stopForm,
            'start_forms'  => $startForms,
        ] + $pages->layoutContext());
    }

    /**
     * Reports page: one page of the time entries of the period (`from`, `to`, `page`).
     */
    public function reports(Request $request): Response
    {
        $this->denyUnlessGrantedAccess();

        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->redirectToRoute('nowo_time_track_index');
        }

        $period = QueryPeriodResolver::resolve($request->query->getString('from'), $request->query->getString('to'), $this->clock?->now());
        $from   = $period['from'];
        $to     = $period['to'];

        $entryPage = $this->timerService->listEntriesPage($user, $from, $to, (int) $request->query->getString('page', '1'), $this->reportsPageSize);

        $pages = $this->pages ?? new TimeTrackPages();
        $query = array_filter(['from' => $request->query->getString('from'), 'to' => $request->query->getString('to')], static fn (string $v): bool => $v !== '');

        return $this->render($this->templates['reports'], [
            'page'       => $pages->reportsPage($entryPage, $from, $to, $request->attributes->getString('_route') ?: null, $query),
            'entries'    => $entryPage->entries,
            'entry_page' => $entryPage,
            'from'       => $from,
            'to'         => $to,
        ] + $pages->layoutContext());
    }

    /**
     * Flat-named form (`_token`, `action`, `task_id`) so the posted contract is unchanged (REQ-TWIG-005).
     *
     * @param array{action?: string, task_id?: string} $data
     *
     * @return FormInterface<mixed>
     */
    private function createTimerActionForm(array $data, string $idSuffix = ''): FormInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = $this->container->get('form.factory');

        return $factory->createNamed('', TimerActionType::class, $data, ['id_suffix' => $idSuffix]);
    }

    private function trans(string $key): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, [], 'NowoTimeTrackBundle') : $key;
    }

    private function denyUnlessGrantedAccess(): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        if (!$this->accessChecker->canAccess($this->getUser())) {
            throw new AccessDeniedException('Access to TimeTrack manage UI is denied.');
        }
    }
}
