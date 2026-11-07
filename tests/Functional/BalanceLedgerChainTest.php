<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Events;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Timesheet\AuditChainVerifier;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use ReflectionObject;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

use function bin2hex;
use function hash;
use function random_bytes;
use function substr;

/**
 * Since 1.16.2: the ledger chain's head and its first line (audit HQ-L3, HQ-L4). Runs on every database of the functional suite.
 */
final class BalanceLedgerChainTest extends FunctionalTestCase
{
    use ClockSensitiveTrait;

    private AppUser $approver;

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'   => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'working_time' => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = $this->createUser('boss@example.com');
        $scopes         = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a'], (string) $this->approver->getId() => ['org-a']];
        $approvers           = self::getContainer()->get(StubTimesheetApproverProvider::class);
        self::assertInstanceOf(StubTimesheetApproverProvider::class, $approvers);
        $approvers->approvers = ['org-a' => [(string) $this->approver->getId() => [(string) $this->user->getId()]]];
    }

    public function testAChainGoesOnAfterTwoLinesInOneMillisecondWithTheOlderIdHigher(): void
    {
        self::mockTime('2026-11-07 10:00:10');
        $first = $this->add();
        // One millisecond's random bits gave the newer line the lower id: the sort alone takes the older line for the head.
        $this->insertAfter($first, new DateTimeImmutable('2026-11-07 10:00:10'), substr($first->getId(), 0, 14) . '0000-8000-000000000000');
        $this->assertChainGoesOn();
    }

    public function testAChainGoesOnAfterALineFromAClockBehind(): void
    {
        self::mockTime('2026-11-07 10:00:10');
        $this->add();
        self::mockTime('2026-11-07 10:00:08');
        $this->add();
        $this->assertChainGoesOn();
    }

    public function testTheSingleScopeKeyOfNoLengthWorksLikeAnyOther(): void
    {
        $store = $this->store();
        $em    = $this->entityManager();
        $owner = (string) $this->user->getId();
        $first = new BalanceLedgerLine($this->user, $owner, '', '2026-10-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-07 10:00:10'), null, 'first');
        $em->persist($first);
        $em->flush();
        $second = new BalanceLedgerLine($this->user, $owner, '', '2026-10-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-07 10:00:09'), $first->getHash(), 'second');
        $em->persist($second);
        $em->flush();

        self::assertSame($second->getId(), $store->findLast($owner, '')?->getId());
        self::assertNull($store->findLast($owner, 'org-a'));
    }

    public function testASecondFirstLineIsRefusedByTheDatabase(): void
    {
        $em    = $this->entityManager();
        $owner = (string) $this->user->getId();
        $em->persist(new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-07 10:00:10'), null, 'first'));
        $em->flush();
        $em->persist(new BalanceLedgerLine($this->user, $owner, 'org-a', '2026-10-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-07 10:00:10'), null, 'second'));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testAConcurrentFirstLineGetsAConflictAndARetrySucceeds(): void
    {
        $em   = $this->entityManager();
        $conn = $em->getConnection();
        $id   = (string) $this->user->getId();
        // Another worker's first line commits after this one read an empty chain, before it writes.
        $listener = new class($conn, $id) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly string $owner)
            {
            }

            public function onFlush(): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $this->connection->insert('time_track_balance_ledger', ['id' => bin2hex(random_bytes(16)), 'effective_date' => '2026-10-01', 'created_at' => '2026-11-07 10:00:00', 'hash' => hash('sha256', 'other worker'), 'owner_ref' => $this->owner, 'scope_key' => 'org-a', 'kind' => 'adjustment', 'seconds' => 60, 'prev_hash' => BalanceLedgerLine::FIRST_LINE_PREV_HASH, 'reason' => 'other worker']);
            }
        };
        $em->getEventManager()->addEventListener(Events::onFlush, $listener);

        try {
            $this->ledger()->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, -60, '2026-10-02', 'mine');
            self::fail('expected a conflict');
        } catch (LedgerRefusedException $exception) {
            self::assertStringContainsString('conflict', $exception->getMessage());
        }
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_balance_ledger'), 'one start');
    }

    public function testAFirstLineReadsAsNullAndHashesAsBefore(): void
    {
        $line = $this->add();

        self::assertNull($line->getPrevHash());
        self::assertSame(BalanceLedgerLine::FIRST_LINE_PREV_HASH, $this->column('prev_hash', $line));
        self::assertNull($line->canonicalRow()['prevHash']);
        $this->assertIntact();
    }

    public function testAFirstLineStoredWithNullBefore1162StaysValid(): void
    {
        $line = $this->add();
        $this->entityManager()->getConnection()->executeStatement('UPDATE time_track_balance_ledger SET prev_hash = NULL WHERE id = ?', [$line->getId()]);
        $this->entityManager()->clear();

        $this->assertIntact();
        $this->user     = $this->managed($this->user);
        $this->approver = $this->managed($this->approver);
        self::assertSame($line->getHash(), $this->add()->getPrevHash());
        $this->assertIntact();
    }

    public function testTwoStartsFromBefore1162AreStillReportedAndNewLinesGoOn(): void
    {
        $conn  = $this->entityManager()->getConnection();
        $first = $this->add();
        $conn->executeStatement('UPDATE time_track_balance_ledger SET prev_hash = NULL WHERE id = ?', [$first->getId()]);
        $this->entityManager()->clear();
        $em     = $this->entityManager();
        $user   = $this->managed($this->user);
        $second = new BalanceLedgerLine($user, (string) $user->getId(), 'org-a', '2026-10-01', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-07 10:00:10'), null, 'second start');
        $em->persist($second);
        $em->flush();
        $conn->executeStatement('UPDATE time_track_balance_ledger SET prev_hash = NULL WHERE id = ?', [$second->getId()]);
        $em->clear();

        self::assertStringContainsString('2 starts', (string) $this->verifier()->verifyLedger((string) $user->getId(), 'org-a'));
        $this->user     = $this->managed($this->user);
        $this->approver = $this->managed($this->approver);
        self::assertNotNull($this->add()->getPrevHash());
        self::assertSame(3, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_track_balance_ledger'));
    }

    private function assertChainGoesOn(): void
    {
        self::mockTime('2026-11-07 12:00:00');
        $next = $this->add();
        self::assertNotNull($next->getPrevHash());
        $this->assertIntact();
    }

    private function assertIntact(): void
    {
        self::assertNull($this->verifier()->verifyLedger((string) $this->user->getId(), 'org-a'));
    }

    private function column(string $column, BalanceLedgerLine $line): mixed
    {
        return $this->entityManager()->getConnection()->fetchOne('SELECT ' . $column . ' FROM time_track_balance_ledger WHERE id = ?', [$line->getId()]);
    }

    private function add(): BalanceLedgerLine
    {
        return $this->ledger()->add($this->approver, $this->user, 'org-a', LedgerKind::Adjustment, -60, '2026-10-02', 'line');
    }

    private function insertAfter(BalanceLedgerLine $previous, DateTimeImmutable $at, string $id): void
    {
        $line      = new BalanceLedgerLine($this->user, (string) $this->user->getId(), 'org-a', '2026-10-02', LedgerKind::Adjustment, -60, $at, $previous->getHash(), 'inverted');
        $reflected = new ReflectionObject($line);
        $reflected->getProperty('id')->setValue($line, $id);
        $reflected->getProperty('hash')->setValue($line, $line->computeHash());
        $this->entityManager()->persist($line);
        $this->entityManager()->flush();
    }

    private function managed(AppUser $user): AppUser
    {
        $found = $this->entityManager()->find(AppUser::class, $user->getId());
        self::assertInstanceOf(AppUser::class, $found);

        return $found;
    }

    private function ledger(): BalanceLedger
    {
        $ledger = self::getContainer()->get(BalanceLedger::class);
        self::assertInstanceOf(BalanceLedger::class, $ledger);

        return $ledger;
    }

    private function store(): BalanceLedgerRepositoryInterface
    {
        $store = self::getContainer()->get(BalanceLedgerRepositoryInterface::class);
        self::assertInstanceOf(BalanceLedgerRepositoryInterface::class, $store);

        return $store;
    }

    private function verifier(): AuditChainVerifier
    {
        $verifier = self::getContainer()->get(AuditChainVerifier::class);
        self::assertInstanceOf(AuditChainVerifier::class, $verifier);

        return $verifier;
    }
}
