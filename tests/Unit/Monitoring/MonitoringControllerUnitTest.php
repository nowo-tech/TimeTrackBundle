<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use Nowo\TimeTrackBundle\Controller\TimeTrackMonitoringApiController;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.26.0: the bundle's own redaction of activity app names (after the client's): e-mail addresses and runs of
 * four or more digits.
 */
final class MonitoringControllerUnitTest extends TestCase
{
    public function testRedact(): void
    {
        self::assertSame('Mail [email]', TimeTrackMonitoringApiController::redact('Mail a.b+c@example.co.uk'));
        self::assertSame('Ticket [digits] - VaultApp 8', TimeTrackMonitoringApiController::redact('Ticket 123456 - VaultApp 8'));
        self::assertSame('[email]', TimeTrackMonitoringApiController::redact(' x@y.z '));
        self::assertSame('Editor [digits] v12', TimeTrackMonitoringApiController::redact('Editor 2026 v12'));
    }
}
