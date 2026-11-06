<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarEventMatcher;
use Nowo\TimeTrackBundle\Calendar\CalendarRefHasher;
use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncService;
use Nowo\TimeTrackBundle\Calendar\CalendarWorkingHours;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarRuleRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarSuggestionRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Stub\DaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FakeCalendarEventSource;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * Since the GG fixes (GG-L5): a failure that cannot even be recorded (store down) is still a result, never an exception.
 */
final class CalendarSyncServiceTest extends TestCase
{
    public function testARowMetTwiceInOneRunIsNotFetchedForever(): void
    {
        $connection  = new CalendarConnection(new TestUser('1', 'u@example.com'), '', CalendarProvider::Google, CalendarConnectionMode::Server, 'c', 'v1', new DateTimeImmutable());
        $offsets     = [];
        $connections = $this->createMock(CalendarConnectionRepositoryInterface::class);
        $connections->method('saveConnection')->willThrowException(new RuntimeException('database gone'));
        $connections->method('findConnectionsDue')->willReturnCallback(static function (DateTimeImmutable $now, ?string $userId, int $limit, int $offset) use (&$offsets, $connection): array {
            $offsets[] = $offset;

            // The next attempt could not be recorded: the row stays due and comes back.
            return $offset === 0 ? [$connection, $connection] : [];
        });
        $settings = new CalendarSettings(true);
        $matcher  = new CalendarEventMatcher(new StubTaskKeyResolver(), new RecordingTaskAccessChecker(), new CalendarRefHasher('s'), $settings);
        $service  = new CalendarSyncService($connections, $this->createMock(CalendarSuggestionRepositoryInterface::class), $this->createMock(CalendarRuleRepositoryInterface::class), $settings, new CalendarWorkingHours(new MutableProfileProvider()), $matcher, [new FakeCalendarEventSource(CalendarProvider::Google, [], CalendarSyncException::RATE_LIMITED)], new StubCalendarCredentialProvider(), null, new MockClock('2026-11-06 12:00:00'));

        self::assertSame(['synced' => 0, 'failed' => 1, 'outside_hours' => 0, 'purged' => 0], $service->syncDue(null, 2));
        self::assertSame([0, 1], $offsets);
    }

    public function testAFailureThatCannotBeRecordedIsStillAResult(): void
    {
        $connections = $this->createMock(CalendarConnectionRepositoryInterface::class);
        $connections->method('saveConnection')->willThrowException(new RuntimeException('database gone'));
        $settings = new CalendarSettings(true);
        $logger   = new RecordingLogger();
        $matcher  = new CalendarEventMatcher(new StubTaskKeyResolver(), new RecordingTaskAccessChecker(), new CalendarRefHasher('s'), $settings);
        $service  = new CalendarSyncService(
            $connections,
            $this->createMock(CalendarSuggestionRepositoryInterface::class),
            $this->createMock(CalendarRuleRepositoryInterface::class),
            $settings,
            new CalendarWorkingHours(new DaysOffProfileProvider(null, null)),
            $matcher,
            [new FakeCalendarEventSource(CalendarProvider::Google, [], CalendarSyncException::RATE_LIMITED)],
            new StubCalendarCredentialProvider(),
            null,
            new MockClock('2026-11-06 12:00:00'),
            $logger,
        );
        $connection = new CalendarConnection(new TestUser('1', 'u@example.com'), '', CalendarProvider::Google, CalendarConnectionMode::Server, 'c', 'v1', new DateTimeImmutable());

        self::assertSame('rate_limited', $service->sync($connection)->errorCode);
        self::assertContains('TimeTrack calendar sync failure could not be recorded.', $logger->messages());
        self::assertContains(RuntimeException::class, $logger->contextValues());
    }
}
