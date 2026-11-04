<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Dto;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientApiSettings;
use Nowo\TimeTrackBundle\Client\IdleCheckSettings;
use Nowo\TimeTrackBundle\Dto\BackdateLimits;
use Nowo\TimeTrackBundle\Dto\ClampedTime;
use PHPUnit\Framework\TestCase;

/**
 * 1.10 value objects: ranges are clamped, wire shapes are stable.
 */
final class V110ValueObjectsTest extends TestCase
{
    public function testBackdateLimitsAreClamped(): void
    {
        $default = new BackdateLimits();
        self::assertSame(86400, $default->maxSeconds);
        self::assertSame(120, $default->clockSkewSeconds);
        self::assertTrue($default->isEnabled());

        $high = new BackdateLimits(999_999, 99_999);
        self::assertSame(86400, $high->maxSeconds);
        self::assertSame(900, $high->clockSkewSeconds);

        $low = new BackdateLimits(-5, -1);
        self::assertSame(0, $low->maxSeconds);
        self::assertSame(0, $low->clockSkewSeconds);
        self::assertFalse($low->isEnabled());
    }

    public function testIdleCheckSettingsAreClamped(): void
    {
        $default = new IdleCheckSettings();
        self::assertTrue($default->enabled);
        self::assertSame(['threshold_minutes' => 10, 'answer_timeout_minutes' => 5], $default->limitsToArray());

        self::assertSame(['threshold_minutes' => 5, 'answer_timeout_minutes' => 1], (new IdleCheckSettings(false, 0, 0))->limitsToArray());
        self::assertSame(['threshold_minutes' => 60, 'answer_timeout_minutes' => 30], (new IdleCheckSettings(true, 61, 31))->limitsToArray());
    }

    public function testClientApiSettingsCarryTheNewValues(): void
    {
        $settings = new ClientApiSettings([], 60, 300, 30, 60, false, 3600, 30, false, false, 15, 3);

        self::assertSame(3600, $settings->backdate->maxSeconds);
        self::assertSame(30, $settings->backdate->clockSkewSeconds);
        self::assertFalse($settings->allowIdleReassign);
        self::assertFalse($settings->idleCheck->enabled);
        self::assertSame(15, $settings->idleCheck->thresholdMinutes);
        self::assertSame(3, $settings->idleCheck->answerTimeoutMinutes);

        $defaults = new ClientApiSettings();
        self::assertSame(86400, $defaults->backdate->maxSeconds);
        self::assertTrue($defaults->allowIdleReassign);
        self::assertTrue($defaults->idleCheck->enabled);
    }

    public function testClampedTimeWireShape(): void
    {
        $time = new ClampedTime(new DateTimeImmutable('2026-11-02T09:00:00+00:00'), new DateTimeImmutable('2026-11-02T09:30:00+00:00'), true, ClampedTime::REASON_OVERLAP);

        self::assertSame(['requested' => '2026-11-02T09:00:00+00:00', 'applied' => '2026-11-02T09:30:00+00:00', 'clamped' => true, 'reason' => 'overlap'], $time->toArray());
        self::assertSame(['requested' => '2026-11-02T09:00:00+00:00', 'applied' => '2026-11-02T09:00:00+00:00', 'clamped' => false, 'reason' => null], (new ClampedTime($time->requested, $time->requested))->toArray());
    }
}
