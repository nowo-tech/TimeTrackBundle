<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Demo approver provider (TimeTrack 1.13 timesheets): nobody approves their own timesheet; the demo user reviews the
 * fixture colleague (TeamLedgerFixtures), so the team ledger page can adjust that colleague's hours balance. A real host
 * answers from its permission model (per tenant scope).
 */
final class DemoApproverProvider implements TimesheetApproverPolicyInterface
{
    public const APPROVER_EMAIL = 'demo@example.com';

    public const COLLEAGUE_EMAIL = 'alex@example.com';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function mayApprove(TimeTrackUserRef $approver, string $ownerId, string $scopeKey): bool
    {
        return $approver->identifier === self::APPROVER_EMAIL && $ownerId !== '' && $ownerId === $this->idOf(self::COLLEAGUE_EMAIL);
    }

    public function reviewableUserIdsFor(TimeTrackUserRef $approver, string $scopeKey): array
    {
        $colleague = $this->idOf(self::COLLEAGUE_EMAIL);

        return $approver->identifier === self::APPROVER_EMAIL && $colleague !== '' ? [$colleague] : [];
    }

    public function approverIdsFor(string $ownerId, string $scopeKey): array
    {
        $approver = $this->idOf(self::APPROVER_EMAIL);

        return $ownerId !== '' && $ownerId === $this->idOf(self::COLLEAGUE_EMAIL) && $approver !== '' ? [$approver] : [];
    }

    private function idOf(string $email): string
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        return $user instanceof User ? UserIdResolver::getId($user) : '';
    }
}
