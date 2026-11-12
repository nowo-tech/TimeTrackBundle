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
use Nowo\TimeTrackBundle\Repository\ConditionalActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use RuntimeException;

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

    public function testConditionalRemoveRunsTheCallbackOnlyForTheCallThatDeletesTheTimer(): void
    {
        $repository = self::getContainer()->get(ActiveTimerRepositoryInterface::class);
        self::assertInstanceOf(ConditionalActiveTimerRepositoryInterface::class, $repository);
        $userId = (string) $this->user->getId();

        $timer = new ActiveTimer($this->user, 'task-1', 'Task', 'board-1', ClientType::Web);
        $repository->save($timer);
        $stale = $repository->findByUserId($userId);
        self::assertInstanceOf(ActiveTimer::class, $stale);

        $calls = 0;
        self::assertTrue($repository->removeIfPresent($timer, static function () use (&$calls): void {
            ++$calls;
        }));
        self::assertNull($repository->findByUserId($userId));
        self::assertFalse($this->entityManager()->contains($timer));

        // The racing request read the same row before it was deleted: its conditional delete affects no row.
        self::assertFalse($repository->removeIfPresent($stale, static function () use (&$calls): void {
            ++$calls;
        }));
        self::assertSame(1, $calls);
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
    }

    public function testConditionalRemoveRollsBackTheDeleteWhenTheCallbackFails(): void
    {
        /** @var ConditionalActiveTimerRepositoryInterface $repository */
        $repository = self::getContainer()->get(ActiveTimerRepositoryInterface::class);
        $timer      = new ActiveTimer($this->user, 'task-1', 'Task', 'board-1', ClientType::Web);
        $repository->save($timer);

        try {
            $repository->removeIfPresent($timer, static function (): never {
                throw new RuntimeException('entry store down');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('entry store down', $exception->getMessage());
        }

        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
        $this->entityManager()->clear();
        self::assertInstanceOf(ActiveTimer::class, $repository->findByUserId((string) $this->user->getId()));
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

    /** Since 1.21 (audit MV-T-L1): the row re-read with a write lock inside the owner's transaction. */
    public function testTimeEntryRepositoryRereadsAnEntryForUpdate(): void
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $entry = new TimeEntry($this->user, 'task-u', 'U', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Web);
        $repository->save($entry);
        $id = $entry->getId();
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_entries SET billable = ? WHERE id = ?', [1, $id]);

        $read = $repository->inUserTransaction($this->user, static fn (): array => [$repository->findForUpdate($id), $repository->findForUpdate('missing')]);
        self::assertSame($entry, $read[0]);
        self::assertTrue($read[0]->getBillable(), 'refreshed from the row');
        self::assertNull($read[1]);
        self::assertSame($entry, $repository->findById($id));
    }

    public function testTimeEntryRepositoryFindsTheLatestEndOfAUser(): void
    {
        $repository = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmTimeEntryRepository::class, $repository);
        $userId = (string) $this->user->getId();
        self::assertNull($repository->findLatestEndedAt($userId));

        $other = $this->createUser('later@example.com');
        $repository->save(new TimeEntry($other, 'task-o', 'O', null, new DateTimeImmutable('2026-11-02 09:00:00'), new DateTimeImmutable('2026-11-02 12:00:00'), 10800, TimeEntrySource::Web));
        $repository->save(new TimeEntry($this->user, 'task-a', 'A', null, new DateTimeImmutable('2026-11-01 08:00:00'), new DateTimeImmutable('2026-11-02 10:00:00'), 3600, TimeEntrySource::Web));
        $repository->save(new TimeEntry($this->user, 'task-b', 'B', null, new DateTimeImmutable('2026-11-02 08:00:00'), new DateTimeImmutable('2026-11-02 09:00:00'), 3600, TimeEntrySource::Web));
        $this->entityManager()->clear();

        self::assertSame('2026-11-02 10:00:00', $repository->findLatestEndedAt($userId)?->format('Y-m-d H:i:s'), 'the latest end, not the latest start; other users ignored');
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
