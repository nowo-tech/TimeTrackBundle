<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionStatus;
use Nowo\TimeTrackBundle\Enum\CalendarMatchKind;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\CalendarSuggestionStatus;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use const DATE_ATOM;

/**
 * Since 1.14 (contract §3.5–§3.7): the calendar rows keep no event data, only codes, and their state changes.
 */
final class CalendarEntitiesTest extends TestCase
{
    public function testTheConnectionLifecycle(): void
    {
        $user       = new TestUser('7', 'u@example.com');
        $connection = new CalendarConnection($user, 'org-a', CalendarProvider::Microsoft, CalendarConnectionMode::Server, 'cred-1', 'v1', new DateTimeImmutable('2026-11-05 10:00:00.75'));

        self::assertSame([$user, 'org-a', CalendarProvider::Microsoft, CalendarConnectionMode::Server, 'cred-1', 'v1', CalendarConnectionStatus::Active], [$connection->getUser(), $connection->getScopeKey(), $connection->getProvider(), $connection->getMode(), $connection->getCredentialRef(), $connection->getConsentVersion(), $connection->getStatus()]);
        self::assertSame('2026-11-05 10:00:00.000000', $connection->getConsentedAt()->format('Y-m-d H:i:s.u'));
        self::assertEquals($connection->getCreatedAt(), $connection->getUpdatedAt());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $connection->getId());

        $connection->markFailed(str_repeat('x', 40), new DateTimeImmutable('2026-11-05 11:00'));
        self::assertSame([CalendarConnectionStatus::Error, str_repeat('x', 32)], [$connection->getStatus(), $connection->getLastErrorCode()]);
        self::assertSame('2026-11-05 11:00', $connection->getUpdatedAt()->format('Y-m-d H:i'));
        self::assertSame([1, '2026-11-05 11:00', null], [$connection->getFailureCount(), $connection->getLastAttemptAt()?->format('Y-m-d H:i'), $connection->getNextAttemptAt()]);
        $connection->markSynced(new DateTimeImmutable('2026-11-05 12:00'), new DateTimeImmutable('2026-11-05 13:00'));
        self::assertSame([0, '2026-11-05 12:00', '2026-11-05 13:00'], [$connection->getFailureCount(), $connection->getLastAttemptAt()?->format('Y-m-d H:i'), $connection->getNextAttemptAt()?->format('Y-m-d H:i')]);
        self::assertSame([CalendarConnectionStatus::Active, null, '2026-11-05 12:00'], [$connection->getStatus(), $connection->getLastErrorCode(), $connection->getLastSyncAt()?->format('Y-m-d H:i')]);
        $connection->markFailed('token_expired', new DateTimeImmutable('2026-11-05 13:00'));
        $connection->reconnect(CalendarConnectionMode::Local, null, 'v2', new DateTimeImmutable('2026-11-05 14:00'));
        self::assertSame([CalendarConnectionMode::Local, null, 'v2', CalendarConnectionStatus::Active, null], [$connection->getMode(), $connection->getCredentialRef(), $connection->getConsentVersion(), $connection->getStatus(), $connection->getLastErrorCode()]);
        self::assertSame('2026-11-05 14:00', $connection->getConsentedAt()->format('Y-m-d H:i'));
        self::assertSame('2026-11-05T12:00:00+00:00', $connection->toArray()['lastSyncAt']);
    }

    public function testTheSuggestionLifecycle(): void
    {
        $user       = new TestUser('7', 'u@example.com');
        $connection = new CalendarConnection($user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'cred-1', 'v1', new DateTimeImmutable('2026-11-05 10:00'));
        $start      = new DateTimeImmutable('2026-11-04T09:00:00Z');
        $suggestion = new CalendarSuggestion($connection, $user, str_repeat('a', 64), str_repeat('b', 64), 'series:bbbbbbbbbbbbbbbb:2026-W45', 'task-1', CalendarMatchKind::Key, $start, $start->modify('+30 minutes'), $start->modify('+14 days'), new DateTimeImmutable('2026-11-05 10:00'));

        self::assertSame([$connection, $user, str_repeat('a', 64), str_repeat('b', 64), 'series:bbbbbbbbbbbbbbbb:2026-W45', 'task-1', CalendarMatchKind::Key, CalendarSuggestionStatus::Pending, null, 1800], [
            $suggestion->getConnection(), $suggestion->getUser(), $suggestion->getEventRef(), $suggestion->getSeriesRef(), $suggestion->getGroupKey(), $suggestion->getTaskId(),
            $suggestion->getMatchKind(), $suggestion->getStatus(), $suggestion->getEntry(), $suggestion->getDurationSeconds(),
        ]);
        self::assertSame('2026-11-18', $suggestion->getExpiresAt()->format('Y-m-d'));
        self::assertSame('2026-11-05', $suggestion->getCreatedAt()->format('Y-m-d'));
        self::assertSame(['id' => $suggestion->getId(), 'startsAt' => $suggestion->getStartsAt()->format(DATE_ATOM), 'endsAt' => $suggestion->getEndsAt()->format(DATE_ATOM), 'seconds' => 1800], $suggestion->toOccurrence());

        self::assertFalse($suggestion->refresh('task-1', CalendarMatchKind::Key, 'series:bbbbbbbbbbbbbbbb:2026-W45', $start, $start->modify('+30 minutes'), $start->modify('+14 days')));
        self::assertTrue($suggestion->refresh('task-2', CalendarMatchKind::Rule, null, $start->modify('+1 hour'), $start->modify('+2 hours'), $start->modify('+15 days')));
        self::assertSame(['task-2', CalendarMatchKind::Rule, null, 3600], [$suggestion->getTaskId(), $suggestion->getMatchKind(), $suggestion->getGroupKey(), $suggestion->getDurationSeconds()]);

        $entry = new TimeEntry($user, 'task-2', 'Task', null, $start, $start->modify('+1 hour'), 3600, TimeEntrySource::Calendar);
        $suggestion->accept($entry);
        self::assertSame([CalendarSuggestionStatus::Accepted, $entry], [$suggestion->getStatus(), $suggestion->getEntry()]);
        self::assertFalse($suggestion->refresh('task-3', CalendarMatchKind::Key, null, $start, $start->modify('+1 hour'), $start), 'decided suggestions keep their state');

        $dismissed = new CalendarSuggestion($connection, $user, str_repeat('c', 64), null, null, 'task-1', CalendarMatchKind::Key, $start, $start->modify('+30 minutes'), $start->modify('+14 days'), new DateTimeImmutable());
        $dismissed->dismiss();
        self::assertSame(CalendarSuggestionStatus::Dismissed, $dismissed->getStatus());
    }

    public function testTheRule(): void
    {
        $user = new TestUser('7', 'u@example.com');
        $rule = new CalendarRule($user, 'org-a', 'daily', 'task-1', true, new DateTimeImmutable('2026-11-05 10:00'));

        self::assertSame([$user, 'org-a', 'daily', 'task-1', true, '2026-11-05'], [$rule->getUser(), $rule->getScopeKey(), $rule->getPattern(), $rule->getTaskId(), $rule->groupsMatches(), $rule->getCreatedAt()->format('Y-m-d')]);
    }
}
