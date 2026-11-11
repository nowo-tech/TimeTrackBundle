<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Doctrine\DBAL\Platforms\SQLitePlatform;
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
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Since 1.18 (DDD adapter audit G1): a firewall user that is not the `user_class` entity drives the services; the
 * bundle stores the `user_class` entity with its `getId()`. Since 1.19 (JV-T3) only classes listed in
 * `accepted_user_classes` are converted and an id with no user row is refused before anything is written, also
 * without enforced foreign keys (SQLite pragma on here, native on MySQL / PostgreSQL).
 */
final class DddSecurityUserTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['accepted_user_classes' => [SecurityUser::class]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->entityManager()->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
    }

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

    public function testASecurityUserWhoseIdHasNoUserRowIsNotStored(): void
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);
        $connection = $this->entityManager()->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
        }

        try {
            $timers->start(new SecurityUser(999999, 'ghost@example.com'), 'task-1', ClientType::Web);
            self::fail('A timer for a user id with no row must not be stored.');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $this->entityManager()->getRepository(ActiveTimer::class)->count([]), 'refused before the flush, even without foreign keys');
    }

    public function testASecurityUserOfAClassThatIsNotAcceptedIsRefused(): void
    {
        $id = $this->user->getId();
        self::assertNotNull($id);
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        $this->expectException(AccessDeniedException::class);
        $auth->issueToken(new TestUser((string) $id, 'person@example.com'), ClientType::Desktop);
    }
}
