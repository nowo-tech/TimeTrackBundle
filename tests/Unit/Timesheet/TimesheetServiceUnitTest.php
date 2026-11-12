<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Command\TimesheetsAutoLockCommand;
use Nowo\TimeTrackBundle\Command\TimesheetsVerifyCommand;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Repository\EntryOwnerRefsRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\EntryOwnersRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRetentionRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetEventRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Timesheet\AuditChainVerifier;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAuditTrail;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetSnapshotBuilder;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function array_values;
use function str_repeat;

/**
 * Since 1.13: state machine branches that the database tests do not reach.
 */
final class TimesheetServiceUnitTest extends TestCase
{
    private TestUser $owner;

    private InMemoryTimesheetRepository $timesheets;

    private InMemoryTimesheetEventRepository $events;

    private InMemoryTimeEntryRepository $entries;

    protected function setUp(): void
    {
        $this->owner      = new TestUser('7', 'ana@example.com');
        $this->timesheets = new InMemoryTimesheetRepository();
        $this->events     = new InMemoryTimesheetEventRepository();
        $this->entries    = new InMemoryTimeEntryRepository();
    }

    public function testOwnersGetARowOnTheirFirstViewAndSubmitWaitsForThePeriodEnd(): void
    {
        $service = $this->service(submitRequiresEnd: true);
        $week    = $service->period($this->owner, 'org-a', 'current');

        $created = $service->view($this->owner, '7', 'org-a', $week);
        self::assertSame($week->key, $created->getPeriod()->key);
        self::assertSame($created, $service->view($this->owner, '7', 'org-a', $week));
        self::assertTrue($service->accessGuard()->hasScope($this->owner, 'org-a'));
        self::assertTrue($service->isEnabled());

        $this->expectError('not_ended', fn (): Timesheet => $service->submit($this->owner, 'org-a', $week));
        $this->expectError('not_found', fn (): Timesheet => $service->withdraw($this->owner, 'org-x', $week));
        self::assertSame(404, (new TimesheetException('not_found'))->httpStatus());
        self::assertSame(422, (new TimesheetException('comment_required'))->httpStatus());
        self::assertSame(409, (new TimesheetException('other'))->httpStatus());
    }

    public function testViewsCreateRowsOnlyForThePreviousAndCurrentPeriods(): void
    {
        $service = $this->service();

        // GB-L1: the current and previous periods are created on the owner's first view (contract §3.2) …
        self::assertFalse($service->view($this->owner, '7', 'org-a', $service->period($this->owner, 'org-a', 'previous'))->isDraft());
        self::assertSame(1, $this->timesheets->saves);
        // … any other period is an unstored open draft, and peek() never writes.
        foreach (['2026-W30', '2026-W46', '2000-W01'] as $key) {
            $draft = $service->view($this->owner, '7', 'org-a', $service->period($this->owner, 'org-a', $key));
            self::assertTrue($draft->isDraft());
            self::assertSame(TimesheetStatus::Open, $draft->getStatus());
            self::assertSame([], $service->events($draft));
        }
        self::assertTrue($service->peek($this->owner, '7', 'org-a', $service->period($this->owner, 'org-a', 'current'))->isDraft());
        self::assertSame(1, $this->timesheets->saves);
        self::assertCount(1, $this->timesheets->timesheets);
        // Submitting a draft period creates its row (first submit).
        self::assertSame(TimesheetStatus::Submitted, $service->submit($this->owner, 'org-a', $service->period($this->owner, 'org-a', '2026-W30'))->getStatus());

        // Keys later than the next period or outside the window are not found (nothing to compute, nothing stored).
        foreach (['2026-W47', '2099-W01', '9998-W52', '1999-W52', '2026-11'] as $key) {
            $this->expectError('not_found', fn (): Period => $service->period($this->owner, 'org-a', $key));
        }
    }

    public function testOffAnswersNothing(): void
    {
        $service = $this->service(enabled: false);
        $week    = Period::fromKey(TimesheetPeriodKind::Week, '2026-W45');

        self::assertFalse($service->isEnabled());
        self::assertSame([], $service->queue(new TestUser('9', 'b@example.com'), 'org-a'));
        $this->expectError('not_found', fn (): Timesheet => $service->view($this->owner, '7', 'org-a', $week));
        $this->expectError('not_found', fn (): Timesheet => $service->find($this->owner, 'x'));
    }

    public function testAConcurrentFirstViewIsAConflict(): void
    {
        $this->timesheets->failSave = $this->createStub(UniqueConstraintViolationException::class);
        $service                    = $this->service();

        $this->expectError('conflict', fn (): Timesheet => $service->view($this->owner, '7', 'org-a', Period::fromKey(TimesheetPeriodKind::Week, '2026-W45')));
    }

    public function testSnapshotsListUnassignedEntriesAndIgnoreOtherDays(): void
    {
        $period  = Period::fromKey(TimesheetPeriodKind::Week, '2026-W45');
        $outside = new TimeEntry($this->owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-09 00:30:00Z'), new DateTimeImmutable('2026-11-09 01:00:00Z'), 1800, TimeEntrySource::Web);
        $none    = new TimeEntry($this->owner, 'task-1', 'T', null, new DateTimeImmutable('2026-11-03 09:00:00Z'), new DateTimeImmutable('2026-11-03 10:00:00Z'), 3600, TimeEntrySource::Web);
        $store   = new class([$outside->assignScopeKey('org-a'), $none]) implements TimeEntryRepositoryInterface {
            /** @param list<TimeEntry> $list */
            public function __construct(private readonly array $list)
            {
            }

            public function save(TimeEntry $entry): void
            {
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return $this->list;
            }
        };

        $snapshot = (new TimesheetSnapshotBuilder($store))->build(null, '7', 'org-a', $period, 'UTC');

        self::assertSame([], $snapshot->entries);
        self::assertSame([$none], $snapshot->unassigned);
        self::assertSame(1, $snapshot->totals['unassignedEntries']);
        self::assertNull($snapshot->balance);
        self::assertNull($snapshot->totals['balanceSeconds']);
    }

    public function testTheVerifierReportsBrokenLedgerChainsAndVanishedTimesheets(): void
    {
        $ledger = new InMemoryBalanceLedgerRepository();
        $first  = new BalanceLedgerLine(null, '7', 'org-a', '2026-11-01', LedgerKind::Opening, 60, new DateTimeImmutable(), null, 'x');
        $fork1  = new BalanceLedgerLine(null, '7', 'org-a', '2026-11-02', LedgerKind::Adjustment, 60, new DateTimeImmutable(), $first->getHash(), 'x');
        $fork2  = new BalanceLedgerLine(null, '7', 'org-a', '2026-11-03', LedgerKind::Adjustment, 60, new DateTimeImmutable(), $first->getHash(), 'x');
        $orphan = new BalanceLedgerLine(null, '8', 'org-a', '2026-11-03', LedgerKind::Adjustment, 60, new DateTimeImmutable(), str_repeat('f', 64), 'x');
        $broken = new BalanceLedgerLine(null, '9', 'org-a', '2026-11-03', LedgerKind::Adjustment, 60, new DateTimeImmutable(), null, 'x');
        (new ReflectionProperty(BalanceLedgerLine::class, 'seconds'))->setValue($broken, 61);
        foreach ([$first, $fork1, $fork2, $orphan, $broken] as $line) {
            $ledger->append($line);
        }
        $retention = new class implements TimesheetRetentionRepositoryInterface {
            public function purgeBefore(string $beforeDate, int $batchSize = 500): array
            {
                return ['timesheets' => 0, 'events' => 0, 'ledger' => 0];
            }

            public function timesheetIds(?string $scopeKey, ?string $ownerRef, string $afterId, int $limit): array
            {
                return $afterId === '' ? ['vanished'] : [];
            }

            public function ledgerChains(?string $scopeKey, ?string $ownerRef): array
            {
                return [['ownerRef' => '7', 'scopeKey' => 'org-a'], ['ownerRef' => '8', 'scopeKey' => 'org-a'], ['ownerRef' => '9', 'scopeKey' => 'org-a']];
            }
        };
        $verifier = new AuditChainVerifier($this->timesheets, $retention, $this->events, $ledger);

        $problems = $verifier->verify();
        self::assertCount(3, $problems);
        self::assertStringContainsString('forks after ' . $first->getHash(), $problems[0]);
        self::assertStringContainsString('1 line(s) are not linked', $problems[1]);
        self::assertStringContainsString('line ' . $broken->getId() . ' is broken', $problems[2]);
        self::assertNull($verifier->verifyTimesheet('vanished'));

        // GB-L3: after a retention purge the chain restarts at the line marked purged_before.
        $restart = new InMemoryBalanceLedgerRepository();
        $kept    = new BalanceLedgerLine(null, '7', 'org-a', '2026-11-02', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-02 10:00:00'), str_repeat('a', 64), 'x');
        $next    = new BalanceLedgerLine(null, '7', 'org-a', '2026-11-03', LedgerKind::Adjustment, 60, new DateTimeImmutable('2026-11-03 10:00:00'), $kept->getHash(), 'x');
        $restart->append($kept);
        $restart->append($next);
        $chain = new AuditChainVerifier($this->timesheets, $retention, $this->events, $restart);
        self::assertStringContainsString('2 line(s) are not linked', (string) $chain->verifyLedger('7', 'org-a'), 'without the purge mark a missing start is tampering');
        (new ReflectionProperty(BalanceLedgerLine::class, 'purgedBefore'))->setValue($kept, new DateTimeImmutable('2026-11-01'));
        self::assertNull($chain->verifyLedger('7', 'org-a'));
        $restart->append(new BalanceLedgerLine(null, '7', 'org-a', '2026-11-04', LedgerKind::Opening, 60, new DateTimeImmutable('2026-11-04 10:00:00'), null, 'x'));
        self::assertStringContainsString('the chain has 2 starts', (string) $chain->verifyLedger('7', 'org-a'));

        $ok = new CommandTester(new TimesheetsVerifyCommand(new AuditChainVerifier($this->timesheets, $retention, $this->events, new InMemoryBalanceLedgerRepository())));
        self::assertSame(0, $ok->execute(['--scope' => 'org-a', '--user' => '7']));
        self::assertStringContainsString('Every audit chain is intact.', $ok->getDisplay());
    }

    public function testAutoLockSkipsEntriesOfOtherScopes(): void
    {
        $user  = $this->owner;
        $store = new class($user) implements TimeEntryRepositoryInterface, EntryOwnersRepositoryInterface {
            public function __construct(private readonly TestUser $user)
            {
            }

            public function save(TimeEntry $entry): void
            {
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [(new TimeEntry($this->user, 'task-1', 'T', null, new DateTimeImmutable('2026-10-06 09:00:00Z'), new DateTimeImmutable('2026-10-06 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-b')];
            }

            public function ownersWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [['user' => $this->user, 'scopeKey' => 'org-a']];
            }
        };
        $command = new TimesheetsAutoLockCommand($this->service(), $store, new TimesheetTimezoneResolver(), 3, new MockClock('2026-11-05 10:00:00'));
        $tester  = new CommandTester($command);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Locked 0 period(s).', $tester->getDisplay());
        self::assertSame([], $this->timesheets->timesheets);
    }

    public function testAutoLockThroughTheFrameworkFreeOwnerListingLocksTheSamePeriods(): void
    {
        // 1.23.0 (REQ-ARCH-001 rule 3): a store implementing only EntryOwnerRefsRepositoryInterface gives the same run.
        $user    = $this->owner;
        $entries = static fn (): array => [(new TimeEntry($user, 'task-1', 'T', null, new DateTimeImmutable('2026-10-06 09:00:00Z'), new DateTimeImmutable('2026-10-06 10:00:00Z'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a')];
        $legacy  = new class($user, $entries) implements TimeEntryRepositoryInterface, EntryOwnersRepositoryInterface {
            public function __construct(private readonly TestUser $user, private readonly Closure $entries)
            {
            }

            public function save(TimeEntry $entry): void
            {
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return ($this->entries)();
            }

            public function ownersWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [['user' => $this->user, 'scopeKey' => 'org-a']];
            }
        };
        $twin = new class($user, $entries) implements TimeEntryRepositoryInterface, EntryOwnerRefsRepositoryInterface {
            public function __construct(private readonly TestUser $user, private readonly Closure $entries)
            {
            }

            public function save(TimeEntry $entry): void
            {
            }

            public function findByUserAndPeriod(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return ($this->entries)();
            }

            public function ownerRefsWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return [['user' => TimeTrackUserRef::of($this->user, TestUser::class, $this->user->getId(), $this->user->getUserIdentifier()), 'scopeKey' => 'org-a']];
            }
        };

        $runs = [];
        foreach (['legacy' => $legacy, 'twin' => $twin] as $kind => $store) {
            $this->timesheets = new InMemoryTimesheetRepository();
            $tester           = new CommandTester(new TimesheetsAutoLockCommand($this->service(), $store, new TimesheetTimezoneResolver(), 3, new MockClock('2026-11-05 10:00:00')));
            $runs[$kind]      = [$tester->execute([]), $tester->getDisplay(), array_map(static fn (Timesheet $timesheet): array => [$timesheet->getUserId(), $timesheet->getScopeKey(), $timesheet->getPeriodStart(), $timesheet->getStatus()], array_values($this->timesheets->timesheets))];
        }

        self::assertStringContainsString('Locked 1 period(s).', $runs['legacy'][1]);
        self::assertSame($runs['legacy'], $runs['twin']);
    }

    private function service(bool $enabled = true, bool $submitRequiresEnd = false): TimesheetService
    {
        $timesheets = $this->timesheets;
        $scopes     = new FixedScopeProvider(['7' => ['org-a'], '9' => ['org-a']]);
        $guard      = new TimesheetAccessGuard($scopes, new StubTimesheetApproverProvider(['org-a' => ['9' => ['7']]]));

        return new TimesheetService(
            $timesheets,
            $this->events,
            new TimesheetAuditTrail($this->events, $timesheets),
            $guard,
            new TimesheetSnapshotBuilder($this->entries),
            new TimesheetTimezoneResolver($scopes),
            new InMemoryActiveTimerRepository(),
            null,
            null,
            new MockClock('2026-11-05 10:00:00'),
            $enabled,
            TimesheetPeriodKind::Week,
            $submitRequiresEnd,
        );
    }

    private function expectError(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('expected ' . $code);
        } catch (TimesheetException $e) {
            self::assertSame($code, $e->errorCode());
        }
    }
}
