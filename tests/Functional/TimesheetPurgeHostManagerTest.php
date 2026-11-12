<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Timesheet\Period;

/**
 * Since 1.22.1 (audit NL): the retention purge runs on the host's `default` entity manager (default
 * `database.entity_manager`) without flushing the host's pending changes, without detaching the host's entities, and
 * nests in a host transaction (savepoint), also when the same repository instance serves several requests of a worker.
 */
final class TimesheetPurgeHostManagerTest extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => StubTimesheetApproverProvider::class]];
    }

    public function testThePurgeNeitherFlushesNorDetachesTheHostsEntities(): void
    {
        $em                                      = $this->entityManager();
        $repository                              = new DoctrineOrmTimesheetRepository($em);
        $owner                                   = (string) $this->user->getId();
        [$old, $event, $opening, $kept, $recent] = $this->seed($repository, $owner);

        // The host's pending work on the same manager: a new entity to insert and a change to a managed bundle entity.
        $pendingUser = new AppUser('pending@example.com', 'secret', ['ROLE_USER']);
        $em->persist($pendingUser);
        $recent->applySubmit(new DateTimeImmutable('2026-11-10 09:00:00'), $owner, str_repeat('b', 64), null);

        self::assertSame(['timesheets' => 1, 'events' => 1, 'ledger' => 1], $repository->purgeBefore('2021-01-01'));

        $connection = $em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['pending@example.com']), 'the host insert is not flushed');
        self::assertSame(TimesheetStatus::Open->value, $connection->fetchOne('SELECT status FROM time_track_timesheets WHERE id = ?', [$recent->getId()]), 'the host update is not flushed');
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($pendingUser));
        self::assertTrue($em->contains($this->user), 'host entities stay managed');
        self::assertTrue($em->contains($recent));
        self::assertTrue($em->contains($pendingUser));

        // Only what the purge deleted or marked is detached, so a later read sees the database.
        self::assertFalse($em->contains($old));
        self::assertFalse($em->contains($event));
        self::assertFalse($em->contains($opening));
        self::assertFalse($em->contains($kept));
        self::assertNull($repository->find($old->getId()));
        self::assertSame('2021-01-01', $em->find(BalanceLedgerLine::class, $kept->getId())?->getPurgedBefore());

        // The host flushes its own work when it decides to.
        $em->flush();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['pending@example.com']));
        self::assertSame(TimesheetStatus::Submitted->value, $connection->fetchOne('SELECT status FROM time_track_timesheets WHERE id = ?', [$recent->getId()]));

        // Next request of a kept-alive worker (no kernel reset): the same repository and manager, the host entity is the
        // same managed instance and nothing is left to purge.
        self::assertSame(['timesheets' => 0, 'events' => 0, 'ledger' => 0], $repository->purgeBefore('2021-01-01'));
        self::assertSame($this->user, $em->find(AppUser::class, $this->user->getId()));
        self::assertSame($recent, $repository->find($recent->getId()));
    }

    public function testThePurgeNestsInAnOpenHostTransaction(): void
    {
        $em         = $this->entityManager();
        $connection = $em->getConnection();
        $repository = new DoctrineOrmTimesheetRepository($em);
        $owner      = (string) $this->user->getId();
        [$old]      = $this->seed($repository, $owner);

        $connection->beginTransaction();
        self::assertSame(['timesheets' => 1, 'events' => 1, 'ledger' => 1], $repository->purgeBefore('2021-01-01'));
        self::assertTrue($connection->isTransactionActive(), 'the host transaction stays open');
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->rollBack();

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_timesheets WHERE id = ?', [$old->getId()]), 'the host rollback undoes the purge');
        self::assertNotNull($repository->find($old->getId()));

        $connection->beginTransaction();
        self::assertSame(['timesheets' => 1, 'events' => 1, 'ledger' => 1], $repository->purgeBefore('2021-01-01'));
        $connection->commit();
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_timesheets WHERE id = ?', [$old->getId()]));
    }

    /**
     * A timesheet and audit row older than the cut-off (with no ledger line pointing to it), a two-line chain whose first
     * line is before the cut-off, and a recent timesheet; all managed.
     *
     * @return array{Timesheet, TimesheetEvent, BalanceLedgerLine, BalanceLedgerLine, Timesheet}
     */
    private function seed(DoctrineOrmTimesheetRepository $repository, string $owner): array
    {
        $em     = $this->entityManager();
        $old    = new Timesheet($this->user, $owner, '', Period::fromKey(TimesheetPeriodKind::Week, '2020-W10'), 'UTC', new DateTimeImmutable('2020-03-01 08:00:00'));
        $recent = new Timesheet($this->user, $owner, '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W45'), 'UTC', new DateTimeImmutable('2026-11-01 08:00:00'));
        $repository->save($old);
        $repository->save($recent);
        $event   = new TimesheetEvent($old, 1, TimesheetAction::Submitted, TimesheetStatus::Open, TimesheetStatus::Submitted, $owner, TimesheetActorRole::Owner, AuditClient::Web, new DateTimeImmutable('2020-03-09 09:00:00'), null);
        $opening = new BalanceLedgerLine($this->user, $owner, '', '2020-01-01', LedgerKind::Opening, 60, new DateTimeImmutable('2020-01-01 08:00:00'), null, 'Opening');
        $kept    = new BalanceLedgerLine($this->user, $owner, '', '2026-11-03', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-03 08:00:00'), $opening->getHash(), 'Adjustment');
        $em->persist($event);
        $em->persist($opening);
        $em->persist($kept);
        $em->flush();

        return [$old, $event, $opening, $kept, $recent];
    }
}
