<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use LogicException;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;

use function sprintf;

/**
 * Doctrine repositories and the metadata listener against a real (SQLite) database (REQ-TEST-003).
 */
final class DoctrineRepositoriesTest extends FunctionalTestCase
{
    public function testTheMetadataListenerAppliesThePrefixAndTheUserClass(): void
    {
        $em = $this->entityManager();

        self::assertSame('time_track_entries', $em->getClassMetadata(TimeEntry::class)->getTableName());
        self::assertSame('time_track_active_timers', $em->getClassMetadata(ActiveTimer::class)->getTableName());
        self::assertSame('time_track_client_tokens', $em->getClassMetadata(ClientToken::class)->getTableName());
        self::assertSame(AppUser::class, $em->getClassMetadata(TimeEntry::class)->getAssociationTargetClass('user'));
    }

    public function testTheMetadataListenerRejectsAnUnknownUserClass(): void
    {
        $em       = $this->entityManager();
        $listener = new TimeTrackMetadataListener('e', 'a', 'c', 'App\\Missing\\User');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Configured user_class "App\\Missing\\User" does not exist.');

        $listener->loadClassMetadata(new LoadClassMetadataEventArgs($em->getClassMetadata(TimeEntry::class), $em));
    }

    public function testActiveTimerRepositoryStoresOneTimerPerUser(): void
    {
        /** @var ActiveTimerRepositoryInterface $repository */
        $repository = self::getContainer()->get(ActiveTimerRepositoryInterface::class);
        $userId     = (string) $this->user->getId();

        self::assertNull($repository->findByUserId($userId));

        $timer = new ActiveTimer($this->user, 'task-1', 'Task', 'board-1', ClientType::Web);
        $repository->save($timer);
        $this->entityManager()->clear();

        $found = $repository->findByUserId($userId);
        self::assertInstanceOf(ActiveTimer::class, $found);
        self::assertSame($timer->getId(), $found->getId());

        $repository->remove($found);
        self::assertNull($repository->findByUserId($userId));
    }

    public function testClientTokenRepositoryIgnoresAndPurgesExpiredTokens(): void
    {
        /** @var ClientTokenRepositoryInterface $repository */
        $repository = self::getContainer()->get(ClientTokenRepositoryInterface::class);
        $repository->save(new ClientToken(ClientAuthService::hashToken('valid'), new DateTimeImmutable('+1 hour'), $this->user, ClientType::Extension));
        $repository->save(new ClientToken(ClientAuthService::hashToken('expired'), new DateTimeImmutable('-1 hour'), $this->user, ClientType::Desktop));

        self::assertSame(ClientType::Extension, $repository->findValidByTokenHash(ClientAuthService::hashToken('valid'))?->getClientType());
        self::assertNull($repository->findValidByTokenHash(ClientAuthService::hashToken('expired')));
        self::assertNull($repository->findValidByTokenHash(ClientAuthService::hashToken('unknown')));

        self::assertSame(1, $repository->purgeExpired());
        self::assertSame(0, $repository->purgeExpired());

        $valid = $repository->findValidByTokenHash(ClientAuthService::hashToken('valid'));
        self::assertInstanceOf(ClientToken::class, $valid);
        $repository->remove($valid);
        self::assertNull($repository->findValidByTokenHash(ClientAuthService::hashToken('valid')));
    }

    public function testTimeEntryRepositoryPagesAndCountsInTheDatabase(): void
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $other = $this->createUser('other@example.com');
        foreach ([[$this->user, 1], [$other, 2], [$this->user, 3], [$this->user, 40]] as [$user, $daysAgo]) {
            $start = new DateTimeImmutable(sprintf('-%d days', $daysAgo));
            $repository->save(new TimeEntry($user, 'task-' . $daysAgo, 'Task ' . $daysAgo, null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Web));
        }
        $from = new DateTimeImmutable('-30 days');
        $to   = new DateTimeImmutable();
        $both = [(string) $this->user->getId(), (string) $other->getId()];

        self::assertSame(['task-1', 'task-3'], $this->taskIds($repository->findByUserAndPeriod((string) $this->user->getId(), $from, $to)));
        self::assertSame(3, $repository->countByUsersAndPeriod($both, $from, $to));
        self::assertSame(['task-1', 'task-2'], $this->taskIds($repository->findPageByUsersAndPeriod($both, $from, $to, 0, 2)));
        self::assertSame(['task-3'], $this->taskIds($repository->findPageByUsersAndPeriod($both, $from, $to, 2, 2)));
        self::assertSame(0, $repository->countByUsersAndPeriod([], $from, $to));
        self::assertSame([], $repository->findPageByUsersAndPeriod([], $from, $to, 0, 10));
    }

    /**
     * @param list<TimeEntry> $entries
     *
     * @return list<string>
     */
    private function taskIds(array $entries): array
    {
        return array_map(static fn (TimeEntry $entry): string => $entry->getTaskId(), $entries);
    }
}
