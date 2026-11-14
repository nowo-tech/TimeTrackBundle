<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Command\PurgeExpiredClientTokensCommand;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgementStoreInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmMonitoringAcknowledgementStore;
use Nowo\TimeTrackBundle\Repository\MonitoringUploadIntentRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Console\Tester\CommandTester;

use function hash;
use function str_repeat;

/**
 * Since 1.26.0: the bundle-owned monitoring stores on the database — the default acknowledgement store (one record per
 * user and version, the first kept, highest version last) and the intent store (conditional state changes, stale
 * uploads, purge after the 24 h replay window).
 */
class MonitoringStoresTest extends MonitoringTestCase
{
    public function testTheDefaultAcknowledgementStore(): void
    {
        $store = self::getContainer()->get(MonitoringAcknowledgementStoreInterface::class);
        self::assertInstanceOf(DoctrineOrmMonitoringAcknowledgementStore::class, $store, 'the standard default');
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);
        $ref   = TimeTrackUserRefFactory::build($user);
        $other = TimeTrackUserRefFactory::build($this->createUser('other@example.com'));

        self::assertNull($store->lastAcknowledged($ref));
        $store->acknowledge($ref, new MonitoringAcknowledgement(3, new DateTimeImmutable('2026-11-01 10:00:00'), 'web', MonitoringFixtures::noticeSha256()));
        $store->acknowledge($ref, new MonitoringAcknowledgement(5, new DateTimeImmutable('2026-11-02 10:00:00'), 'desktop', MonitoringFixtures::noticeSha256()));
        $store->acknowledge($ref, new MonitoringAcknowledgement(5, new DateTimeImmutable('2026-11-03 10:00:00'), 'web', hash('sha256', 'x')));
        $last = $store->lastAcknowledged($ref);
        self::assertSame([5, 'desktop', '2026-11-02 10:00:00'], [$last?->policyVersion, $last?->clientType, $last?->acknowledgedAt->format('Y-m-d H:i:s')], 'the first record of a version is kept');
        self::assertNull($store->lastAcknowledged($other));
        self::assertNull($store->lastAcknowledged(TimeTrackUserRef::ofId(AppUser::class, '999999')));
        self::assertNull($store->lastAcknowledged(new TimeTrackUserRef(AppUser::class, null)));
        $store->acknowledge(TimeTrackUserRef::of($user, AppUser::class, null, 'x'), new MonitoringAcknowledgement(9, new DateTimeImmutable(), 'web', MonitoringFixtures::noticeSha256()));
        self::assertSame(5, $store->lastAcknowledged($ref)?->policyVersion, 'a reference without an id stores nothing');

        $this->expectException(LogicException::class);
        $store->acknowledge(TimeTrackUserRef::ofId(AppUser::class, '1'), new MonitoringAcknowledgement(9, new DateTimeImmutable(), 'web', MonitoringFixtures::noticeSha256()));
    }

    public function testTheIntentStore(): void
    {
        $store = self::getContainer()->get(MonitoringUploadIntentRepositoryInterface::class);
        self::assertInstanceOf(MonitoringUploadIntentRepositoryInterface::class, $store);
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);
        $userId = (string) $user->getId();
        $now    = $this->at('+0 seconds');
        $slot   = $now->setTimestamp($now->getTimestamp() - $now->getTimestamp() % 600);
        $make   = static fn (string $key, DateTimeImmutable $created): MonitoringUploadIntent => new MonitoringUploadIntent($user, hash('sha256', $key), str_repeat('b', 64), 'entry-1', $slot, $now, 7, true, 'image/webp', 10, 1, 1, str_repeat('s', 64), $created);

        $old = $make('old', $now->modify('-25 hours'));
        $old->signUntil($now->modify('-25 hours'));
        $store->save($old);
        $fresh = $make('fresh', $now)->signUntil($now->modify('+120 seconds'));
        $store->save($fresh);

        self::assertNull($store->findForUser($fresh->getId(), '999999'));
        self::assertSame($fresh->getId(), $store->findByKey($userId, hash('sha256', 'fresh'))?->getId());
        self::assertTrue($store->slotTaken($userId, $slot, $old->getId(), $now), 'the fresh pending URL holds the slot');
        self::assertFalse($store->slotTaken($userId, $slot, $fresh->getId(), $now), 'an expired pending one does not');
        self::assertFalse($store->slotTaken($userId, $slot->modify('+600 seconds'), '', $now), 'another slot');

        $until = $fresh->getSignedUntil();
        self::assertNotNull($until);
        self::assertFalse($store->consume($fresh->getId(), $until, $until), 'never once expired');
        self::assertTrue($store->consume($fresh->getId(), $until, $now));
        self::assertFalse($store->consume($fresh->getId(), $until, $now), 'single use');
        self::assertFalse($store->sign($fresh->getId(), $now->modify('+300 seconds'), $now->modify('-300 seconds')), 'an upload in progress is not signed again');
        self::assertTrue($store->sign($fresh->getId(), $now->modify('+300 seconds'), $now->modify('+1 hour')), 'a stuck upload is');
        self::assertSame(MonitoringUploadIntent::STATUS_PENDING, $store->findForUser($fresh->getId(), $userId)?->getStatus());
        $store->release($fresh->getId());
        self::assertSame(MonitoringUploadIntent::STATUS_PENDING, $store->findForUser($fresh->getId(), $userId)?->getStatus(), 'only an upload in progress is released');
        self::assertTrue($store->consume($fresh->getId(), $now->modify('+300 seconds'), $now));
        $store->markStored($fresh->getId(), 'shot-9');
        $stored = $store->findForUser($fresh->getId(), $userId);
        self::assertSame([MonitoringUploadIntent::STATUS_STORED, 'shot-9'], [$stored?->getStatus(), $stored?->getScreenshotId()]);
        self::assertFalse($store->sign($fresh->getId(), $now->modify('+600 seconds'), $now->modify('+1 hour')), 'a stored intent is never signed again');
        self::assertTrue($store->slotTaken($userId, $slot, $old->getId(), $now));
        $store->markRejected($fresh->getId());
        self::assertSame(MonitoringUploadIntent::STATUS_STORED, $store->findForUser($fresh->getId(), $userId)?->getStatus(), 'only an upload in progress is rejected');

        $command = self::getContainer()->get(PurgeExpiredClientTokensCommand::class);
        self::assertInstanceOf(PurgeExpiredClientTokensCommand::class, $command);
        self::assertSame(0, (new CommandTester($command))->execute([]));
        self::assertNull($store->findForUser($old->getId(), $userId), 'purged after the 24 h replay window');
        self::assertNotNull($store->findForUser($fresh->getId(), $userId));
        self::assertSame(1, $store->purgeCreatedBefore($now->modify('+1 second'), $userId));
    }
}
