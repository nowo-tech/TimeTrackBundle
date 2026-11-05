<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormHandler;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * TimeTrack 1.13 showcase: the signed-in user's timesheet of a week (TimeTrack:TimesheetWeekGrid) and the hours balance
 * of that week (TimeTrack:BalanceCard), with submit / withdraw posted back through TimesheetFormHandler. The host owns
 * the routes, the layout and the permissions; the bundle components are configured, never overridden.
 */
final class TimesheetController extends AbstractController
{
    public function __construct(
        private readonly TimesheetService $timesheets,
        private readonly WorkingTimeBalanceService $balance,
        private readonly TimesheetFormHandler $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/timesheet', name: 'app_timesheet', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            throw $this->createAccessDeniedException();
        }
        $scope = '';
        try {
            $period    = $this->timesheets->period($user, $scope, $request->query->getString('period') ?: 'current');
            $timesheet = $this->timesheets->view($user, UserIdResolver::getId($user), $scope, $period);
        } catch (TimesheetException) {
            throw $this->createNotFoundException();
        }

        return $this->render('timesheet/index.html.twig', [
            'timesheet' => $timesheet,
            'report'    => $this->balance->report($user, $scope, $period->start, $period->end),
            'previous'  => $period->previous()->key,
            'next'      => $period->next()->key,
        ]);
    }

    #[Route('/timesheet/{action}', name: 'app_timesheet_action', requirements: ['action' => 'submit|withdraw'], methods: ['POST'])]
    public function action(Request $request, string $action): Response
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            throw $this->createAccessDeniedException();
        }
        try {
            $timesheet = $this->forms->handleDecision($request, $user, $action);
            $this->addFlash('success', $this->translator->trans('demo.timesheet.flash.' . $action));
        } catch (TimesheetException $e) {
            $this->addFlash('danger', $this->translator->trans('timesheet.error.' . $e->errorCode(), [], 'NowoTimeTrackBundle'));

            return $this->redirectToRoute('app_timesheet');
        }

        return $this->redirectToRoute('app_timesheet', ['period' => $timesheet->getPeriod()->key]);
    }
}
