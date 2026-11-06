<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarEventView;
use Nowo\TimeTrackBundle\Calendar\CalendarException;
use Nowo\TimeTrackBundle\Calendar\CalendarRefHasher;
use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncResult;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use PHPUnit\Framework\TestCase;

use function print_r;
use function serialize;
use function unserialize;

/**
 * Since 1.14 (contract §6.1, §6.2, §3.6): credentials and event views never leak (redacted debug output, no
 * serialization); event references are keyed per user and stable.
 */
final class CalendarValueObjectsTest extends TestCase
{
    public function testTheCredentialIsRedactedAndNeverSerialized(): void
    {
        $credential = new CalendarCredential('fake-secret-token');

        self::assertSame('fake-secret-token', $credential->reveal());
        self::assertStringNotContainsString('fake-secret-token', print_r($credential, true));
        self::assertSame(['accessToken' => '[redacted]'], $credential->__debugInfo());
        $this->assertThrowsLogic(static fn (): string => serialize($credential));
        $this->assertThrowsLogic(static function () use ($credential): void {
            $credential->__unserialize([]);
        });
    }

    public function testTheEventViewIsRedactedAndNeverSerialized(): void
    {
        $view = new CalendarEventView('evt-secret', 'series-secret', 'MARKER-TITLE', new DateTimeImmutable('2026-11-05 09:00'), new DateTimeImmutable('2026-11-05 10:00'));

        $debug = print_r($view, true);
        foreach (['evt-secret', 'series-secret', 'MARKER-TITLE'] as $secret) {
            self::assertStringNotContainsString($secret, $debug);
        }
        self::assertFalse($view->allDay || $view->cancelled || $view->free || $view->declined || $view->otherKind);
        $this->assertThrowsLogic(static fn (): string => serialize($view));
        $this->assertThrowsLogic(static function () use ($view): void {
            $view->__unserialize([]);
        });
        $this->assertThrowsLogic(static fn (): mixed => unserialize('O:47:"Nowo\TimeTrackBundle\Calendar\CalendarEventView":0:{}'));
    }

    public function testReferencesAreKeyedPerUserAndStable(): void
    {
        $hasher = new CalendarRefHasher('kernel-secret');
        $start  = new DateTimeImmutable('2026-11-05T09:00:00+01:00');
        $ref    = $hasher->eventRef('7', CalendarProvider::Google, 'evt-1', $start);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ref);
        self::assertSame($ref, $hasher->eventRef('7', CalendarProvider::Google, 'evt-1', new DateTimeImmutable('2026-11-05T08:00:00Z')), 'the same instant in another zone');
        self::assertNotSame($ref, $hasher->eventRef('8', CalendarProvider::Google, 'evt-1', $start), 'another user');
        self::assertNotSame($ref, $hasher->eventRef('7', CalendarProvider::Microsoft, 'evt-1', $start), 'another provider');
        self::assertNotSame($ref, $hasher->eventRef('7', CalendarProvider::Google, 'evt-1', $start->modify('+1 day')), 'another occurrence');
        self::assertNotSame($ref, (new CalendarRefHasher('other-secret'))->eventRef('7', CalendarProvider::Google, 'evt-1', $start), 'another installation');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hasher->seriesRef('7', CalendarProvider::Google, 'series-1'));
        self::assertNotSame($hasher->seriesRef('7', CalendarProvider::Google, 'evt-1'), $hasher->eventRef('7', CalendarProvider::Google, 'evt-1', $start));
    }

    public function testSettingsResultAndException(): void
    {
        $settings = new CalendarSettings(true, ['google'], ['local']);

        self::assertTrue($settings->allowsProvider(CalendarProvider::Google));
        self::assertFalse($settings->allowsProvider(CalendarProvider::Microsoft));
        self::assertTrue($settings->allowsMode(CalendarConnectionMode::Local));
        self::assertFalse($settings->allowsMode(CalendarConnectionMode::Server));
        self::assertTrue((new CalendarSyncResult('c', ['fetched' => 0, 'matched' => 0, 'ignored' => 0, 'new' => 0, 'updated' => 0, 'expired' => 0, 'stale' => 0]))->isSuccess());
        self::assertFalse((new CalendarSyncResult('c', ['fetched' => 0, 'matched' => 0, 'ignored' => 0, 'new' => 0, 'updated' => 0, 'expired' => 0, 'stale' => 0], 'rate_limited'))->isSuccess());
        self::assertSame('invalid_scope', (new CalendarException('invalid_scope'))->errorCode);
    }

    private function assertThrowsLogic(callable $operation): void
    {
        try {
            $operation();
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }
    }
}
