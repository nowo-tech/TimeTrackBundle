<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;

/**
 * Since 1.18 (DDD adapter audit G1): a firewall user that is not the `user_class` entity drives the services; the
 * bundle stores a Doctrine reference built from its `getId()` (SQLite, real foreign keys).
 */
final class DddSecurityUserTest extends FunctionalTestCase
{
    public function testTheDefaultPortIsTheDoctrineResolver(): void
    {
        self::assertInstanceOf(DoctrineUserReferenceResolver::class, self::getContainer()->get(UserReferenceResolverInterface::class));
    }

    public function testATimerStartedAndStoppedWithASecurityUserWrapperIsStoredForTheEntity(): void
    {
        $id = $this->user->getId();
        self::assertNotNull($id);
        $this->entityManager()->clear();
        $security = new SecurityUser($id, 'person@example.com');

        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $timer = $timers->start($security, 'task-1', ClientType::Web);
        self::assertInstanceOf(AppUser::class, $timer->getUser());
        self::assertSame((string) $id, $timer->getUserId());

        $this->entityManager()->clear();
        $stored = $this->entityManager()->find(ActiveTimer::class, $timer->getId());
        self::assertInstanceOf(ActiveTimer::class, $stored);
        self::assertSame((string) $id, $stored->getUserId());

        $entry = $timers->stop($security);
        self::assertInstanceOf(TimeEntry::class, $entry);
        $this->entityManager()->clear();

        $found = $this->entityManager()->find(TimeEntry::class, $entry->getId());
        self::assertInstanceOf(TimeEntry::class, $found);
        self::assertSame((string) $id, $found->getUserId());
        $owner = $found->getUser();
        self::assertInstanceOf(AppUser::class, $owner);
        self::assertSame('person@example.com', $owner->getUserIdentifier());
    }

    public function testAClientTokenIssuedForASecurityUserWrapperResolvesToTheEntity(): void
    {
        $id = $this->user->getId();
        self::assertNotNull($id);
        $this->entityManager()->clear();

        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $issued = $auth->issueToken(new SecurityUser($id, 'person@example.com'), ClientType::Desktop);
        $this->entityManager()->clear();

        $token = $auth->resolveToken($issued['token']);
        self::assertInstanceOf(ClientToken::class, $token);
        $owner = $token->getUser();
        self::assertInstanceOf(AppUser::class, $owner);
        self::assertSame($id, $owner->getId());
    }
}
