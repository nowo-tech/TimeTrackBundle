<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Timesheet\DemoApproverProvider;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * TimeTrack 1.39 showcase (/team/ledger): a colleague the demo user reviews, with a few balance movements written by
 * the demo user through the ledger (hash chain). Idempotent: skipped when the colleague exists (`make up` appends).
 */
final class TeamLedgerFixtures extends Fixture implements DependentFixtureInterface
{
    /** [days ago, minutes, reason] */
    private const LINES = [
        [20, 120, 'Opening hours carried over'],
        [12, -30, 'Doctor visit'],
        [6, 45, 'Customer call after hours'],
    ];

    public function __construct(
        private readonly BalanceLedger $ledger,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $demo = $manager->getRepository(User::class)->findOneBy(['email' => DemoApproverProvider::APPROVER_EMAIL]);
        if (!$demo instanceof User || $manager->getRepository(User::class)->findOneBy(['email' => DemoApproverProvider::COLLEAGUE_EMAIL]) instanceof User) {
            return;
        }
        $colleague = (new User())->setEmail(DemoApproverProvider::COLLEAGUE_EMAIL)->setRoles(['ROLE_USER']);
        $colleague->setPassword($this->passwordHasher->hashPassword($colleague, 'demo'));
        $manager->persist($colleague);
        $manager->flush();

        $today = new DateTimeImmutable('today');
        foreach (self::LINES as [$daysAgo, $minutes, $reason]) {
            $this->ledger->add($demo, $colleague, '', LedgerKind::Adjustment, $minutes * 60, $today->modify('-' . $daysAgo . ' days')->format('Y-m-d'), $reason);
        }
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}
