<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use Nowo\TimeTrackBundle\Timesheet\Period;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function str_repeat;

final class TimesheetEntitiesTest extends TestCase
{
    public function testANewTimesheetIsOpenAndUnlocked(): void
    {
        $user      = new TestUser('7', 'ana@example.com');
        $timesheet = new Timesheet($user, '7', 'org-a', Period::fromKey(TimesheetPeriodKind::Month, '2026-11'), 'Europe/Madrid', new DateTimeImmutable('2026-11-05 10:00:00.5'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7/', $timesheet->getId());
        self::assertSame($user, $timesheet->getUser());
        self::assertSame('7', $timesheet->getOwnerRef());
        self::assertSame('org-a', $timesheet->getScopeKey());
        self::assertSame(TimesheetPeriodKind::Month, $timesheet->getPeriodKind());
        self::assertSame('2026-11', $timesheet->getPeriod()->key);
        self::assertSame('2026-11-01', $timesheet->getPeriodStart());
        self::assertSame('2026-11-30', $timesheet->getPeriodEnd());
        self::assertSame('Europe/Madrid', $timesheet->getTimezone());
        self::assertSame(TimesheetStatus::Open, $timesheet->getStatus());
        self::assertFalse($timesheet->isLocked());
        self::assertNull($timesheet->getLockedAt());
        self::assertNull($timesheet->getLockReason());
        self::assertNull($timesheet->getSubmittedAt());
        self::assertNull($timesheet->getSubmittedByRef());
        self::assertNull($timesheet->getDecidedAt());
        self::assertNull($timesheet->getDecidedByRef());
        self::assertNull($timesheet->getDecisionComment());
        self::assertNull($timesheet->getTotals());
        self::assertNull($timesheet->getEntriesHash());
        self::assertNull($timesheet->getHeadHash());
        self::assertSame(1, $timesheet->getVersion());
        self::assertSame('000000', $timesheet->getCreatedAt()->format('u'));
        self::assertEquals($timesheet->getCreatedAt(), $timesheet->getUpdatedAt());
    }

    public function testCoversUsesTheTimesheetZone(): void
    {
        $timesheet = TimesheetFixtures::open(new TestUser('7', 'a@example.com'), 'org-a', '2026-W45', 'Europe/Madrid');

        // Sunday 2026-11-08 23:30 in Madrid is still week 45; 23:30 UTC on Sunday is Monday 00:30 in Madrid.
        self::assertTrue($timesheet->covers(new DateTimeImmutable('2026-11-08 22:30:00Z')));
        self::assertFalse($timesheet->covers(new DateTimeImmutable('2026-11-08 23:30:00Z')));
        self::assertTrue($timesheet->covers(new DateTimeImmutable('2026-11-01 23:30:00Z')));
    }

    public function testTransitions(): void
    {
        $timesheet = TimesheetFixtures::open(new TestUser('7', 'a@example.com'), 'org-a', '2026-W45');
        $hash      = str_repeat('b', 64);

        $timesheet->applySubmit(new DateTimeImmutable('2026-11-06 17:00:00'), '7', $hash, ['entries' => 2]);
        self::assertSame(TimesheetStatus::Submitted, $timesheet->getStatus());
        self::assertSame('7', $timesheet->getSubmittedByRef());
        self::assertSame('2026-11-06 17:00:00', $timesheet->getSubmittedAt()?->format('Y-m-d H:i:s'));
        self::assertSame($hash, $timesheet->getEntriesHash());
        self::assertSame(['entries' => 2], $timesheet->getTotals());

        $timesheet->applyWithdraw(new DateTimeImmutable('2026-11-06 18:00:00'));
        self::assertSame(TimesheetStatus::Open, $timesheet->getStatus());

        $timesheet->applyReject(new DateTimeImmutable('2026-11-07 09:00:00'), '9', 'Missing Friday');
        self::assertSame(TimesheetStatus::Rejected, $timesheet->getStatus());
        self::assertSame('9', $timesheet->getDecidedByRef());
        self::assertSame('Missing Friday', $timesheet->getDecisionComment());

        $timesheet->applySubmit(new DateTimeImmutable('2026-11-07 10:00:00'), '7', $hash, null);
        self::assertNull($timesheet->getDecisionComment(), 'the rejection comment stays until the next submit');

        $timesheet->applyApprove(new DateTimeImmutable('2026-11-07 11:00:00'), '9', $hash, ['entries' => 2]);
        self::assertSame(TimesheetStatus::Approved, $timesheet->getStatus());
        self::assertTrue($timesheet->isLocked());
        self::assertSame(TimesheetLockReason::Approved, $timesheet->getLockReason());
        self::assertSame('2026-11-07 11:00:00', $timesheet->getDecidedAt()?->format('Y-m-d H:i:s'));

        $timesheet->applyReopen(new DateTimeImmutable('2026-11-08 09:00:00'), '9', 'Wrong task');
        self::assertSame(TimesheetStatus::Open, $timesheet->getStatus());
        self::assertFalse($timesheet->isLocked());
        self::assertNull($timesheet->getLockReason());
        self::assertSame('Wrong task', $timesheet->getDecisionComment());

        $timesheet->applyLock(new DateTimeImmutable('2026-11-20 03:00:00'), TimesheetLockReason::Auto);
        self::assertSame(TimesheetLockReason::Auto, $timesheet->getLockReason());
        self::assertSame(TimesheetStatus::Open, $timesheet->getStatus(), 'auto-lock keeps the status');
        $timesheet->applyApprove(new DateTimeImmutable('2026-11-21 03:00:00'), '9', $hash, null);
        self::assertSame('2026-11-20 03:00:00', $timesheet->getLockedAt()?->format('Y-m-d H:i:s'), 'approve keeps the existing lock time');
        self::assertSame(TimesheetLockReason::Approved, $timesheet->getLockReason());

        $timesheet->moveHead(str_repeat('c', 64), new DateTimeImmutable('2026-11-21 04:00:00'));
        self::assertSame(str_repeat('c', 64), $timesheet->getHeadHash());
        self::assertSame('2026-11-21 04:00:00', $timesheet->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAuditRowsChainAndDetectTampering(): void
    {
        $timesheet = TimesheetFixtures::open(new TestUser('7', 'a@example.com'), 'org-a', '2026-W45');
        $first     = new TimesheetEvent($timesheet, 1, TimesheetAction::Submitted, TimesheetStatus::Open, TimesheetStatus::Submitted, '7', TimesheetActorRole::Owner, AuditClient::Desktop, new DateTimeImmutable('2026-11-06 17:00:00'), null, null, str_repeat('d', 64), ['entries' => 1]);
        $second    = new TimesheetEvent($timesheet, 2, TimesheetAction::Rejected, TimesheetStatus::Submitted, TimesheetStatus::Rejected, '9', TimesheetActorRole::Approver, AuditClient::Web, new DateTimeImmutable('2026-11-07 09:00:00'), $first->getHash(), 'Fix it');

        self::assertSame(CanonicalJson::hash($first->canonicalRow()), $first->getHash());
        self::assertSame($first->getHash(), $second->getPrevHash());
        self::assertSame(CanonicalJson::hash($second->canonicalRow(), $first->getHash()), $second->computeHash());
        self::assertSame($timesheet, $second->getTimesheet());
        self::assertSame(2, $second->getSeq());
        self::assertSame(TimesheetAction::Rejected, $second->getAction());
        self::assertSame(TimesheetStatus::Submitted, $second->getFromStatus());
        self::assertSame(TimesheetStatus::Rejected, $second->getToStatus());
        self::assertSame('9', $second->getActorRef());
        self::assertSame(TimesheetActorRole::Approver, $second->getActorRole());
        self::assertSame('Fix it', $second->getComment());
        self::assertNull($second->getEntriesHash());
        self::assertNull($second->getTotals());
        self::assertSame(['entries' => 1], $first->getTotals());
        self::assertSame(str_repeat('d', 64), $first->getEntriesHash());
        self::assertSame(AuditClient::Web, $second->getClient());
        self::assertSame('2026-11-07 09:00:00', $second->getOccurredAt()->format('Y-m-d H:i:s'));
        self::assertNotSame('', $second->getId());
        self::assertSame('2026-11-07T09:00:00Z', $second->canonicalRow()['occurredAt']);

        (new ReflectionProperty(TimesheetEvent::class, 'comment'))->setValue($second, 'Fix it!');
        self::assertNotSame($second->getHash(), $second->computeHash(), 'one changed byte breaks the hash');
    }

    public function testLedgerLines(): void
    {
        $user      = new TestUser('7', 'a@example.com');
        $timesheet = TimesheetFixtures::open($user, 'org-a', '2026-W45');
        $line      = new BalanceLedgerLine($user, '7', 'org-a', '2026-11-06', LedgerKind::OvertimeRatified, 3600, new DateTimeImmutable('2026-11-07 11:00:00'), null, null, $timesheet, null, '9');
        $reversal  = new BalanceLedgerLine($user, '7', 'org-a', '2026-11-06', LedgerKind::Reversal, -3600, new DateTimeImmutable('2026-11-08 11:00:00'), $line->getHash(), 'Reopened', null, $line->getId(), '9');

        self::assertSame(CanonicalJson::hash($line->canonicalRow()), $line->getHash());
        self::assertSame($line->getHash(), $line->computeHash());
        self::assertSame($user, $line->getUser());
        self::assertSame('7', $line->getOwnerRef());
        self::assertSame('org-a', $line->getScopeKey());
        self::assertSame('2026-11-06', $line->getEffectiveDate());
        self::assertSame(LedgerKind::OvertimeRatified, $line->getKind());
        self::assertSame(3600, $line->getSeconds());
        self::assertNull($line->getReason());
        self::assertSame($timesheet, $line->getTimesheet());
        self::assertNull($line->getReversesId());
        self::assertSame('9', $line->getActorRef());
        self::assertNull($line->getPrevHash());
        self::assertSame('2026-11-07 11:00:00', $line->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame($line->getHash(), $reversal->getPrevHash());
        self::assertSame($line->getId(), $reversal->getReversesId());
        self::assertSame('Reopened', $reversal->getReason());
        self::assertSame(['id', 'scopeKey', 'effectiveDate', 'kind', 'seconds', 'reason', 'timesheetId', 'reversesId', 'createdAt'], array_keys($reversal->toArray()));
        self::assertSame($timesheet->getId(), $line->toArray()['timesheetId']);
        self::assertNotSame('', $line->getId());
    }
}
