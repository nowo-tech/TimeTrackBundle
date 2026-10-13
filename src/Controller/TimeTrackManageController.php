<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Security\TimeTrackAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\QueryPeriodResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TimeTrackManageController extends AbstractController
{
    /**
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TimerService $timerService,
        private readonly TimeTrackAccessCheckerInterface $accessChecker,
        private readonly array $templates,
        private readonly bool $allowUnauthenticated = false,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->denyUnlessGrantedAccess();

        $user   = $this->getUser();
        $active = $user instanceof UserInterface ? $this->timerService->getActive($user) : null;
        $tasks  = $user instanceof UserInterface ? $this->timerService->listTasks($user, new TaskListQuery(limit: 20)) : [];

        if ($request->isMethod('POST') && $user instanceof UserInterface) {
            if (!$this->isCsrfTokenValid('nowo_time_track_manage', (string) $request->request->get('_token', ''))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $action = (string) $request->request->get('action', '');
            $taskId = (string) $request->request->get('task_id', '');

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

        return $this->render($this->templates['index'], [
            'active_timer' => $active,
            'tasks'        => $tasks,
        ]);
    }

    public function reports(Request $request): Response
    {
        $this->denyUnlessGrantedAccess();

        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            return $this->redirectToRoute('nowo_time_track_index');
        }

        $period = QueryPeriodResolver::resolve($request->query->getString('from'), $request->query->getString('to'));
        $from   = $period['from'];
        $to     = $period['to'];

        $entries = $this->timerService->listEntries($user, $from, $to);

        return $this->render($this->templates['reports'], [
            'entries' => $entries,
            'from'    => $from,
            'to'      => $to,
        ]);
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
