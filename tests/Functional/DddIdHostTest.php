<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\SecurityUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;

use function array_map;
use function sort;

/**
 * 1.23.0 (REQ-ARCH-001 rules 2 and 6, the DDD host case): `user_mapping: id`, `database.mapping_type: xml` and
 * `database.private_entity_manager: true`. The `user_class` is a plain security user that is no Doctrine entity at all;
 * the bundle rows reference its class and id in the bundle-owned `time_track_host_users` table (no foreign key to your
 * users table), ids compare byte for byte, and releasing a user deletes its row with the timers and tokens behind it.
 */
final class DddIdHostTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return [
            'user_class'            => SecurityUser::class,
            'accepted_user_classes' => [AppUser::class],
            'user_mapping'          => 'id',
            'database'              => ['mapping_type' => 'xml', 'private_entity_manager' => true],
        ];
    }

    public function testTheBundleRowsReferenceTheBundleOwnedHostUsersOnly(): void
    {
        $schema = $this->entityManager()->getConnection()->createSchemaManager();
        self::assertContains('time_track_host_users', $schema->listTableNames());
        foreach (['time_track_entries', 'time_track_active_timers', 'time_track_client_tokens', 'time_track_timesheets'] as $table) {
            $targets = array_map(static fn (ForeignKeyConstraint $key): string => $key->getForeignTableName(), $schema->listTableForeignKeys($table));
            self::assertContains('time_track_host_users', $targets, $table);
            self::assertNotContains('app_user', $targets, $table);
        }
    }

    public function testASecurityUserThatIsNoEntityDrivesTheServices(): void
    {
        $ana    = new SecurityUser('ext-7', 'ana@example.com');
        $timers = $this->timers();
        $timers->start($ana, 'task-1', ClientType::Web);
        $entry = $timers->stop($ana);
        self::assertInstanceOf(TimeEntry::class, $entry);

        self::assertSame('ext-7', $entry->getUserId());
        $owner = $entry->getUser();
        self::assertInstanceOf(HostUser::class, $owner);
        $ref = TimeTrackUserRefs::fromUser($owner);
        self::assertSame([SecurityUser::class, 'ext-7'], [$ref->userClass, $ref->id], 'the twins see your class and id');
        self::assertSame([['id' => 'ext-7', 'user_class' => SecurityUser::class]], $this->entityManager()->getConnection()->fetchAllAssociative('SELECT id, user_class FROM time_track_host_users'));
        self::assertCount(1, $this->entries()->findByUserAndPeriod('ext-7', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')));

        // A second write of the same user reuses its row.
        $timers->start($ana, 'task-2', ClientType::Web);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_host_users'));
    }

    public function testTheClientApiWithAnAcceptedSecurityUser(): void
    {
        $token = $this->login();
        $me    = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertSame((string) $this->user->getId(), $me['userId'] ?? null);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('task-1', $this->api('GET', '/api/time-track/timer', null, $token)['timer']['taskId'] ?? null);
    }

    public function testUserIdsCompareByteForByte(): void
    {
        $this->timers()->start(new SecurityUser('abc', 'lower'), 'task-1', ClientType::Web);
        $this->timers()->stop(new SecurityUser('abc', 'lower'));
        $this->timers()->start(new SecurityUser('abc', 'lower'), 'task-2', ClientType::Web);

        $period = [new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day')];
        self::assertSame([], $this->entries()->findByUserAndPeriod('ABC', ...$period));
        self::assertCount(1, $this->entries()->findByUserAndPeriod('abc', ...$period));
        $timers = self::getContainer()->get(ActiveTimerRepositoryInterface::class);
        self::assertInstanceOf(ActiveTimerRepositoryInterface::class, $timers);
        self::assertNull($timers->findByUserId('ABC'));
        self::assertInstanceOf(ActiveTimer::class, $timers->findByUserId('abc'));
        self::assertNull($this->timers()->getActive(new SecurityUser('ABC', 'upper')));
    }

    public function testReleasingAUserDeletesItsRowWithItsTimersAndTokens(): void
    {
        $connection = $this->entityManager()->getConnection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
        $leaving = new SecurityUser('leaving-1', 'leaving');
        $staying = new SecurityUser('staying-1', 'staying');
        $this->timers()->start($leaving, 'task-1', ClientType::Web);
        $entry = $this->timers()->stop($leaving);
        self::assertInstanceOf(TimeEntry::class, $entry);
        $this->timers()->start($leaving, 'task-2', ClientType::Web);
        $this->timers()->start($staying, 'task-3', ClientType::Web);
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $auth->issueToken($leaving, ClientType::Desktop);
        $auth->issueToken($staying, ClientType::Desktop);

        $people = self::getContainer()->get(FormerPersonTimeEntries::class);
        self::assertInstanceOf(FormerPersonTimeEntries::class, $people);
        $people->release($leaving, 'Leaving Person');

        $ids = $connection->fetchFirstColumn('SELECT id FROM time_track_host_users');
        sort($ids);
        self::assertSame(['staying-1'], $ids);
        self::assertSame(['staying-1'], $connection->fetchFirstColumn('SELECT user_id FROM time_track_active_timers'));
        self::assertSame(['staying-1'], $connection->fetchFirstColumn('SELECT user_id FROM time_track_client_tokens'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE user_id IS NULL AND person_ref IS NOT NULL'), 'the stopped and the saved timer are kept as a former person\'s');
    }

    private function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }

    private function entries(): TimeEntryRepositoryInterface
    {
        $entries = self::getContainer()->get(TimeEntryRepositoryInterface::class);
        self::assertInstanceOf(TimeEntryRepositoryInterface::class, $entries);

        return $entries;
    }
}
