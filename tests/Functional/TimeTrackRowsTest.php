<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmMonitoringUploadIntentRepository;

/**
 * 1.27.0 (Beacon spec 239): a new row refused by its unique key (another request wrote it first) is thrown with the
 * entity manager still open, outside a transaction and inside one (only the row's savepoint is rolled back).
 */
final class TimeTrackRowsTest extends FunctionalTestCase
{
    public function testALostInsertOutsideATransactionLeavesTheManagerOpen(): void
    {
        $timers = $this->timers();
        $first  = new ActiveTimer($this->user, 'task-1', 'T', null, ClientType::Web);
        $timers->save($first);
        self::assertTrue($this->entityManager()->contains($first), 'managed and clean, as after a flush');

        try {
            $timers->save(new ActiveTimer($this->user, 'task-2', 'T', null, ClientType::Web));
            self::fail('The user has a running timer.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertTrue($this->entityManager()->isOpen());

        $first->heartbeat(true, new DateTimeImmutable());
        $timers->save($first);
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_active_timers'));
    }

    public function testALostInsertInsideATransactionRollsBackItsSavepointOnly(): void
    {
        $connection = $this->entityManager()->getConnection();
        $timers     = $this->timers();
        $connection->beginTransaction();
        $timers->save(new ActiveTimer($this->user, 'task-1', 'T', null, ClientType::Web));
        try {
            $timers->save(new ActiveTimer($this->user, 'task-2', 'T', null, ClientType::Web));
            self::fail('The user has a running timer.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertTrue($connection->isTransactionActive(), 'the caller\'s transaction goes on');
        $connection->commit();

        self::assertSame('task-1', $connection->fetchOne('SELECT task_id FROM time_track_active_timers'));
    }

    public function testALostIntentKeyIsThrown(): void
    {
        $intents = self::getContainer()->get(DoctrineOrmMonitoringUploadIntentRepository::class);
        self::assertInstanceOf(DoctrineOrmMonitoringUploadIntentRepository::class, $intents);
        $intent = fn (): MonitoringUploadIntent => new MonitoringUploadIntent($this->user, str_repeat('k', 64), str_repeat('b', 64), 'entry-1', new DateTimeImmutable('2026-11-02 10:00:00'), new DateTimeImmutable('2026-11-02 10:01:00'), 1, true, 'image/webp', 10, 10, 10, str_repeat('s', 64), new DateTimeImmutable('2026-11-02 10:01:00'));
        $intents->save($intent());

        try {
            $intents->save($intent());
            self::fail('The key is taken.');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertTrue($this->entityManager()->isOpen());
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_track_monitoring_upload_intents'));
    }

    private function timers(): DoctrineOrmActiveTimerRepository
    {
        $timers = self::getContainer()->get(DoctrineOrmActiveTimerRepository::class);
        self::assertInstanceOf(DoctrineOrmActiveTimerRepository::class, $timers);

        return $timers;
    }
}
