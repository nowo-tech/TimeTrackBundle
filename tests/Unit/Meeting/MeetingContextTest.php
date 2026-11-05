<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Meeting;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Meeting\MeetingContext;
use Nowo\TimeTrackBundle\Meeting\MeetingSettings;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Since 1.14 (contract §22.8): `GET me` meeting keys — off without a provider or when the host says disabled, a failing
 * provider counts as off (logged with the exception class only), the poll hint is bounded, the Calendars link is https.
 */
final class MeetingContextTest extends TestCase
{
    public function testSummary(): void
    {
        $user     = new TestUser('1', 'u@example.com');
        $provider = new StubMeetingProvider();
        $logger   = new RecordingLogger();
        $context  = new MeetingContext(new MeetingSettings(true, refreshAfterSeconds: 300), $provider, null, $logger);

        self::assertSame(['status' => 'connected', 'refreshAfterSeconds' => 300], $context->summary($user));
        $provider->state = new MeetingSyncState(MeetingSyncStatus::NeedsReconnect, new DateTimeImmutable(), 10);
        self::assertSame(['status' => 'needs_reconnect', 'refreshAfterSeconds' => 60], $context->summary($user));
        self::assertSame(3600, $context->refreshAfter(new MeetingSyncState(MeetingSyncStatus::Syncing, null, 99_999)));
        $provider->state = new MeetingSyncState(MeetingSyncStatus::Disabled);
        self::assertNull($context->summary($user));
        $provider->failState = true;
        $provider->failWith  = 'SECRET-STORE-DETAIL';
        self::assertNull($context->summary($user));
        self::assertSame(['exception' => RuntimeException::class], ['exception' => $logger->records[0]['context']['exception'] ?? null]);
        self::assertNotContains('state store down SECRET-STORE-DETAIL', $logger->contextValues());

        self::assertNull((new MeetingContext(new MeetingSettings(false), $provider))->summary($user), 'disabled');
        self::assertNull((new MeetingContext(new MeetingSettings(true)))->provider(), 'no provider');
    }

    public function testCalendarsLink(): void
    {
        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnMap([
            ['app_calendars', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://beacon.example/account/integrations/calendars'],
            ['app_http', [], UrlGeneratorInterface::ABSOLUTE_URL, 'http://beacon.example/calendars'],
        ]);
        $failing = $this->createMock(UrlGeneratorInterface::class);
        $failing->method('generate')->willThrowException(new RuntimeException('no route'));

        self::assertSame('https://beacon.example/account/integrations/calendars', (new MeetingContext(new MeetingSettings(true, calendarsRoute: 'app_calendars'), null, $generator))->calendarsLink());
        self::assertNull((new MeetingContext(new MeetingSettings(true, calendarsRoute: 'app_http'), null, $generator))->calendarsLink(), 'https only');
        self::assertNull((new MeetingContext(new MeetingSettings(true, calendarsRoute: 'missing'), null, $failing))->calendarsLink());
        self::assertNull((new MeetingContext(new MeetingSettings(true), null, $generator))->calendarsLink(), 'no route configured');
        self::assertNull((new MeetingContext(new MeetingSettings(true, calendarsRoute: 'app_calendars')))->calendarsLink(), 'no router');
    }
}
