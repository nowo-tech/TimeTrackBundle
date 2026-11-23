<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Timesheet\DemoApproverProvider;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Ui\TimesheetDialogResponder;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * TimeTrack 1.39 showcase: the hours balance ledger of a colleague the demo user reviews (TimeTrack:LedgerTable). The
 * add and reversal dialogs load their form on demand: the table renders links to the two dialog routes below and one
 * empty remote dialog; each route serves the fragment (X-Nowo-Dialog: 1) or the full page, and handles the post
 * through TimesheetDialogResponder (422 in place, 409 on a conflict, 204 + X-Nowo-Redirect once saved).
 */
final class TeamLedgerController extends AbstractController
{
    private const SCOPE = '';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BalanceLedger $ledger,
        private readonly TimesheetDialogResponder $dialogs,
    ) {
    }

    #[Route('/team/ledger', name: 'app_team_ledger', methods: ['GET'])]
    public function index(): Response
    {
        $colleague = $this->colleague();

        return $this->render('team/ledger.html.twig', [
            'colleague' => $colleague,
            'ownerId'   => UserIdResolver::getId($colleague),
            'scope'     => self::SCOPE,
            'lines'     => $this->ledger->linesOf(UserIdResolver::getId($colleague), self::SCOPE),
        ]);
    }

    #[Route('/team/ledger/adjust', name: 'app_team_ledger_adjust', methods: ['GET', 'POST'])]
    public function adjust(Request $request): Response
    {
        return $this->dialogs->ledger($request, $this->viewer(), $this->colleague(), self::SCOPE, $this->generateUrl('app_team_ledger'), null, ['layout' => 'base.html.twig', 'flash' => 'Balance movement added.']);
    }

    #[Route('/team/ledger/reverse', name: 'app_team_ledger_reverse', methods: ['GET', 'POST'])]
    public function reverse(Request $request): Response
    {
        return $this->dialogs->ledger($request, $this->viewer(), $this->colleague(), self::SCOPE, $this->generateUrl('app_team_ledger'), $request->query->getString('line'), ['layout' => 'base.html.twig', 'flash' => 'Balance movement reversed.']);
    }

    private function viewer(): UserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function colleague(): User
    {
        $this->viewer();
        $colleague = $this->entityManager->getRepository(User::class)->findOneBy(['email' => DemoApproverProvider::COLLEAGUE_EMAIL]);
        if (!$colleague instanceof User) {
            throw $this->createNotFoundException('Load the demo fixtures (make setup).');
        }

        return $colleague;
    }
}
