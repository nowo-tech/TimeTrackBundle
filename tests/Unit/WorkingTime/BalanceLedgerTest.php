<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\WorkingTime;

use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Event\BalanceLedgerLineAddedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function str_repeat;

final class BalanceLedgerTest extends TestCase
{
    private TestUser $owner;

    private TestUser $approver;

    private InMemoryBalanceLedgerRepository $lines;

    private InMemoryTimesheetRepository $timesheets;

    /** @var list<BalanceLedgerLineAddedEvent> */
    private array $added = [];

    private RecordingLogger $logger;

    private BalanceLedger $ledger;

    protected function setUp(): void
    {
        $this->owner      = new TestUser('7', 'ana@example.com');
        $this->approver   = new TestUser('9', 'boss@example.com');
        $this->lines      = new InMemoryBalanceLedgerRepository();
        $this->timesheets = new InMemoryTimesheetRepository();
        $this->logger     = new RecordingLogger();
        $dispatcher       = new EventDispatcher();
        $dispatcher->addListener(TimeTrackEvents::BALANCE_LEDGER_LINE_ADDED, function (BalanceLedgerLineAddedEvent $event): void {
            $this->added[] = $event;
        });
        $access       = new TimesheetAccessGuard(new FixedScopeProvider(['7' => ['org-a'], '9' => ['org-a']]), new StubTimesheetApproverProvider(['org-a' => ['9' => ['7']]]));
        $this->ledger = new BalanceLedger($this->lines, $access, new PeriodLockGuard($this->timesheets), $dispatcher, new MockClock('2026-11-05 10:00:00'), $this->logger);
    }

    public function testLinesAreChainedAndAnnounced(): void
    {
        $opening = $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Opening, 7200, '2026-10-01', "  Carried\u{202E} over  ");
        $paid    = $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::OvertimePaid, -3600, '2026-10-31', 'Paid in October');
        $comp    = $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::OvertimeCompensated, 3600, '2026-11-02', null);
        $undo    = $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Reversal, 999, '2026-11-03', 'Typo', $paid->getId());

        self::assertSame('Carried over', $opening->getReason());
        self::assertNull($opening->getPrevHash());
        self::assertSame($opening->getHash(), $paid->getPrevHash());
        self::assertSame($comp->getHash(), $undo->getPrevHash());
        self::assertSame(3600, $undo->getSeconds(), 'a reversal is the opposite of the reversed line');
        self::assertSame($paid->getId(), $undo->getReversesId());
        self::assertSame('9', $undo->getActorRef());
        self::assertSame(CanonicalJson::hash($undo->canonicalRow(), $comp->getHash()), $undo->getHash());
        self::assertCount(4, $this->added);
        self::assertSame([$opening->getId(), '7', 'org-a', LedgerKind::Opening, 7200, '9'], [$this->added[0]->lineId, $this->added[0]->ownerRef, $this->added[0]->scopeKey, $this->added[0]->kind, $this->added[0]->seconds, $this->added[0]->actorRef]);
        self::assertSame(4, $this->lines->flushes);
        self::assertSame(['bundle' => 'time_track', 'action' => 'ledger_add', 'kind' => 'opening'], $this->logger->records[0]['context']);
        self::assertCount(4, $this->ledger->linesOf('7', 'org-a'));
        self::assertCount(1, $this->ledger->linesOf('7', 'org-a', '2026-11-01', '2026-11-02'));
    }

    /**
     * @return iterable<string, array{LedgerKind, int, string, string|null, string}>
     */
    public static function refusals(): iterable
    {
        yield 'ratified by hand' => [LedgerKind::OvertimeRatified, 3600, '2026-10-01', 'x', 'ratify_only'];
        yield 'bad date' => [LedgerKind::Adjustment, 3600, '2026-13-01', 'x', 'invalid_date'];
        yield 'no reason' => [LedgerKind::Adjustment, 3600, '2026-10-01', " \u{200B} ", 'reason_required'];
        yield 'zero adjustment' => [LedgerKind::Adjustment, 0, '2026-10-01', 'x', 'invalid_seconds'];
        yield 'positive paid' => [LedgerKind::OvertimePaid, 3600, '2026-10-01', 'x', 'invalid_seconds'];
        yield 'negative compensated' => [LedgerKind::OvertimeCompensated, -3600, '2026-10-01', null, 'invalid_seconds'];
        yield 'huge' => [LedgerKind::Opening, 40_000_000, '2026-10-01', 'x', 'invalid_seconds'];
        yield 'reversal of nothing' => [LedgerKind::Reversal, 0, '2026-10-01', 'x', 'not_found'];
    }

    #[DataProvider('refusals')]
    public function testRulesRefuseLines(LedgerKind $kind, int $seconds, string $date, ?string $reason, string $code): void
    {
        try {
            $this->ledger->add($this->approver, $this->owner, 'org-a', $kind, $seconds, $date, $reason);
            self::fail($code);
        } catch (LedgerRefusedException $e) {
            self::assertSame($code, $e->errorCode());
            self::assertStringContainsString($code, $e->getMessage());
        }
        self::assertSame([], $this->lines->lines);
    }

    public function testWhoMayAddAndTheOneOpeningRule(): void
    {
        foreach ([[$this->owner, 'org-a'], [$this->approver, 'org-b']] as [$actor, $scope]) {
            try {
                $this->ledger->add($actor, $this->owner, $scope, LedgerKind::Adjustment, 60, '2026-10-01', 'x');
                self::fail('forbidden');
            } catch (LedgerRefusedException $e) {
                self::assertSame('forbidden', $e->errorCode());
            }
        }

        $opening = $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Opening, 60, '2026-10-01', 'x');
        try {
            $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Opening, 60, '2026-10-01', 'x');
            self::fail('opening_exists');
        } catch (LedgerRefusedException $e) {
            self::assertSame('opening_exists', $e->errorCode());
        }
        $reversal = $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Reversal, 0, '2026-10-02', 'Wrong', $opening->getId());
        foreach ([[$opening->getId(), 'already_reversed'], [$reversal->getId(), 'not_reversible']] as [$target, $code]) {
            try {
                $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Reversal, 0, '2026-10-02', 'Again', $target);
                self::fail($code);
            } catch (LedgerRefusedException $e) {
                self::assertSame($code, $e->errorCode());
            }
        }
        $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Opening, 120, '2026-10-01', 'Second opening after the reversal');
        self::assertCount(3, $this->lines->lines);
    }

    public function testWithTimesheetsOffNoPeriodIsLocked(): void
    {
        $this->timesheets->save(TimesheetFixtures::locked($this->owner, 'org-a', '2026-W40'));
        $access = new TimesheetAccessGuard(new FixedScopeProvider(['7' => ['org-a'], '9' => ['org-a']]), new StubTimesheetApproverProvider(['org-a' => ['9' => ['7']]]));
        $ledger = new BalanceLedger($this->lines, $access, new PeriodLockGuard($this->timesheets, false));

        self::assertSame(60, $ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Adjustment, 60, '2026-10-01', 'x')->getSeconds());
    }

    public function testALockedPeriodRefusesLinesButNotTheApprovalsRatification(): void
    {
        $locked = TimesheetFixtures::locked($this->owner, 'org-a', '2026-W40', 'UTC', TimesheetLockReason::Approved);
        $this->timesheets->save($locked);

        try {
            $this->ledger->add($this->approver, $this->owner, 'org-a', LedgerKind::Adjustment, 60, '2026-10-01', 'x');
            self::fail('locked');
        } catch (PeriodLockedException $e) {
            self::assertSame('2026-10-04', $e->getLockedThrough());
        }

        $ratified = $this->ledger->ratify($this->owner, '7', $locked, 5400, '9');
        self::assertSame('2026-10-04', $ratified->getEffectiveDate());
        self::assertSame($locked, $ratified->getTimesheet());
        self::assertSame(0, $this->lines->flushes, 'the approval flushes in its own transaction');
        $this->ledger->dispatch($ratified);
        self::assertCount(1, $this->added);

        $second = $this->ledger->ratify($this->owner, '7', $locked, 600, '9');
        $undo   = $this->ledger->reverseRatified($this->owner, $locked, '9', str_repeat('r', 600));
        self::assertCount(2, $undo);
        self::assertSame($second->getHash(), $undo[0]->getPrevHash());
        self::assertSame($undo[0]->getHash(), $undo[1]->getPrevHash(), 'reversals in one call chain to each other');
        self::assertSame(-5400, $undo[0]->getSeconds());
        self::assertSame(500, mb_strlen((string) $undo[0]->getReason()));
        self::assertSame([], $this->ledger->reverseRatified($this->owner, $locked, '9', 'again'));
        self::assertInstanceOf(BalanceLedgerLine::class, $this->lines->findReversalOf($second->getId()));
        self::assertSame('2026-11-05 10:00:00', $undo[1]->getCreatedAt()->format('Y-m-d H:i:s'));
    }
}
