<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Enum;

use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use PHPUnit\Framework\TestCase;

final class TimesheetEnumsTest extends TestCase
{
    public function testLedgerKinds(): void
    {
        foreach ([LedgerKind::Opening, LedgerKind::Adjustment, LedgerKind::OvertimePaid, LedgerKind::Reversal] as $kind) {
            self::assertTrue($kind->movesBalance());
            self::assertTrue($kind->requiresReason());
        }
        foreach ([LedgerKind::OvertimeRatified, LedgerKind::OvertimeCompensated] as $kind) {
            self::assertFalse($kind->movesBalance());
            self::assertFalse($kind->requiresReason());
        }
    }

    public function testEditableStatuses(): void
    {
        self::assertTrue(TimesheetStatus::Open->isEditable());
        self::assertTrue(TimesheetStatus::Rejected->isEditable());
        self::assertFalse(TimesheetStatus::Submitted->isEditable());
        self::assertFalse(TimesheetStatus::Approved->isEditable());
    }

    public function testAuditClientOfATokenType(): void
    {
        self::assertSame(AuditClient::Desktop, AuditClient::fromClientType(ClientType::Desktop));
        self::assertSame(AuditClient::Extension, AuditClient::fromClientType(ClientType::Extension));
        self::assertSame(AuditClient::Api, AuditClient::fromClientType(ClientType::Web));
    }

    public function testManualAndCalendarSourcesAreLiveAndEditable(): void
    {
        foreach ([TimeEntrySource::Manual, TimeEntrySource::Calendar] as $source) {
            self::assertTrue($source->isLive());
            self::assertFalse($source->isReadOnly());
        }
    }
}
