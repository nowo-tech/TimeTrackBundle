<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Calendar\CalendarEventMatcher;
use Nowo\TimeTrackBundle\Calendar\CalendarEventView;
use Nowo\TimeTrackBundle\Calendar\CalendarException;
use Nowo\TimeTrackBundle\Calendar\CalendarRuleService;
use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncResult;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncService;
use Nowo\TimeTrackBundle\Calendar\CalendarWorkingHours;
use Nowo\TimeTrackBundle\Command\CalendarSyncCommand;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionStatus;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\CalendarSuggestionStatus;
use Nowo\TimeTrackBundle\Event\CalendarConnectionChangedEvent;
use Nowo\TimeTrackBundle\Event\CalendarSyncFinishedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Support\FakeCalendarEventSource;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_column;
use function array_count_values;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function dirname;
use function explode;
use function file_get_contents;
use function json_encode;
use function ksort;
use function preg_replace;
use function rtrim;
use function serialize;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function trim;

use const DATE_ATOM;

/**
 * Since 1.14 (contract §3.5–§3.9, §6, §12.2, AC-14, AC-16 grouping, AC-17, AC-19, AC-20 schema): calendar connections,
 * the sync with fake sources, dailies grouping, rules, revoke, the sync command and the reference migrations — against
 * the test database (SQLite by default; MySQL and PostgreSQL with TIME_TRACK_TEST_DATABASE_URL).
 */
final class CalendarSyncDatabaseTest extends FunctionalTestCase
{
    /** Marker carried by every fake event title: it must never reach a row, a log line, an event or an exception. */
    private const MARKER = 'ZZ-PRIVATE-TITLE-MARKER';

    private const NOW = '2026-11-06T12:00:00Z';

    private MockClock $clock;

    private EventDispatcher $dispatcher;

    /** @var list<object> */
    private array $events = [];

    private RecordingLogger $logger;

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets' => ['scope_provider' => FixedScopeProvider::class],
            'calendar'   => [
                'enabled'             => true,
                'task_key_resolver'   => StubTaskKeyResolver::class,
                'credential_provider' => StubCalendarCredentialProvider::class,
                'modes'               => ['server', 'local'],
                'max_rules_per_user'  => 3,
            ],
            'client_api' => ['profile_provider' => MutableProfileProvider::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a', 'org-b']];
        $this->clock         = new MockClock(self::NOW);
        $this->logger        = new RecordingLogger();
        $this->dispatcher    = new EventDispatcher();
        $this->events        = [];
        foreach ([TimeTrackEvents::CALENDAR_CONNECTION_CHANGED, TimeTrackEvents::CALENDAR_SYNC_FINISHED] as $name) {
            $this->dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testASyncKeepsOnlyReferencesAndGroupsDailies(): void
    {
        $connection = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', '2026-11');
        $this->rules()->create($this->user, 'org-a', '  Stand-up  ', 'task-2');
        $source = new FakeCalendarEventSource(CalendarProvider::Google, [
            ...$this->dailies('evt-daily', 'series-daily', '#task-1 Daily ' . self::MARKER),
            $this->event('evt-single', null, '2026-11-04T15:00:00Z', 3600, 'Review #task-3 ' . self::MARKER),
            $this->event('evt-rule', 'series-standup', '2026-11-05T07:00:00Z', 900, 'Team stand-up ' . self::MARKER),
            $this->event('evt-private', null, '2026-11-05T10:00:00Z', 3600, 'Dentist ' . self::MARKER),
        ]);

        $result = $this->sync($source)->sync($connection);

        self::assertTrue($result->isSuccess());
        self::assertSame(['fetched' => 8, 'matched' => 7, 'ignored' => 1, 'new' => 7, 'updated' => 0, 'expired' => 0, 'stale' => 0], $result->counts);
        self::assertSame('fake-token-google', $source->calls[0][0]);
        self::assertSame('2026-10-23T12:00:00+00:00', $source->calls[0][1]->format(DATE_ATOM), 'lookback 14 days');
        $rows = $this->rows('time_track_calendar_suggestions');
        self::assertCount(7, $rows);
        $groups = array_column($rows, 'group_key');
        self::assertCount(1, array_unique(array_filter($groups, static fn (?string $g): bool => $g !== null && str_starts_with($g, 'series:'))), 'five dailies in one group');
        self::assertCount(5, array_filter($groups, static fn (?string $g): bool => $g !== null && str_starts_with($g, 'series:') && str_ends_with($g, ':2026-W45')));
        self::assertCount(1, array_filter($groups, static fn (?string $g): bool => $g !== null && str_starts_with($g, 'rule:')));
        self::assertContains(null, $groups, 'a single event has no group');
        $kinds = array_count_values(array_column($rows, 'match_kind'));
        ksort($kinds);
        self::assertSame(['key' => 6, 'rule' => 1], $kinds);
        self::assertSame('2026-11-18 16:00:00', (new DateTimeImmutable((string) $this->rowOf($rows, 'task_id', 'task-3')['expires_at']))->format('Y-m-d H:i:s'), 'expiry = end + 14 days');
        $this->assertNoEventData();

        $connection = $this->repository()->findConnection($connection->getId());
        self::assertInstanceOf(CalendarConnection::class, $connection);
        self::assertSame(CalendarConnectionStatus::Active, $connection->getStatus());
        self::assertSame('2026-11-06 12:00:00', $connection->getLastSyncAt()?->format('Y-m-d H:i:s'));
        $finished = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof CalendarSyncFinishedEvent));
        self::assertCount(1, $finished);
        self::assertNull($finished[0]->errorCode);
    }

    public function testAReSyncKeepsDecisionsAndFollowsMovedEvents(): void
    {
        $connection = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', '2026-11');
        $source     = new FakeCalendarEventSource(CalendarProvider::Google, [
            $this->event('evt-a', null, '2026-11-04T09:00:00Z', 1800, '#task-1 ' . self::MARKER),
            $this->event('evt-b', null, '2026-11-04T10:00:00Z', 1800, '#task-2 ' . self::MARKER),
        ]);
        $sync = $this->sync($source);
        $sync->sync($connection);
        $repo    = $this->repository();
        [$a, $b] = $repo->findSuggestionsByUser((string) $this->user->getId(), 'org-a', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-07'), [CalendarSuggestionStatus::Pending]);
        $b->dismiss();
        $repo->flushSuggestions();

        // evt-a changes task, evt-b (dismissed) changes task too, evt-a2 is the same occurrence moved by the provider.
        $source->events = [
            $this->event('evt-a', null, '2026-11-04T09:00:00Z', 1800, '#task-3 ' . self::MARKER),
            $this->event('evt-b', null, '2026-11-04T10:00:00Z', 1800, '#task-3 ' . self::MARKER),
        ];
        $this->clock->modify('+2 hours');
        $result = $sync->sync($connection);

        self::assertSame(['new' => 0, 'updated' => 1], ['new' => $result->counts['new'], 'updated' => $result->counts['updated']]);
        $this->entityManager()->clear();
        $a = $repo->findSuggestionsByIds((string) $this->user->getId(), [$a->getId(), $b->getId()]);
        self::assertSame(['task-3', 'task-2'], array_map(static fn (CalendarSuggestion $s): string => $s->getTaskId(), $a));
        self::assertSame([CalendarSuggestionStatus::Pending, CalendarSuggestionStatus::Dismissed], array_map(static fn (CalendarSuggestion $s): CalendarSuggestionStatus => $s->getStatus(), $a));
        self::assertSame(0, $sync->sync($this->reload($connection->getId()))->counts['updated'], 'nothing changed');

        // Expiry: 14 days after the end, the rows are purged by the next sync.
        $this->clock->modify('+15 days');
        $source->events = [];
        self::assertSame(2, $sync->sync($this->reload($connection->getId()))->counts['expired']);
    }

    public function testFailuresAreRecordedAsCodes(): void
    {
        $google    = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', '2026-11');
        $microsoft = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Microsoft, CalendarConnectionMode::Server, 'host-cred-2', '2026-11');
        $local     = $this->connections()->connect($this->user, 'org-b', CalendarProvider::Google, CalendarConnectionMode::Local, null, '2026-11');
        $source    = new FakeCalendarEventSource(CalendarProvider::Google, [], CalendarSyncException::RATE_LIMITED);
        $sync      = $this->sync($source);

        self::assertSame(CalendarSyncException::RATE_LIMITED, $sync->sync($google)->errorCode);
        self::assertSame([CalendarConnectionStatus::Error, 'rate_limited'], [$google->getStatus(), $google->getLastErrorCode()]);
        self::assertSame(CalendarSyncException::NO_SOURCE, $sync->sync($microsoft)->errorCode);
        self::assertSame('not_syncable', $sync->sync($local)->errorCode, 'local mode is read on the device');
        $this->credentials()->hasToken = false;
        self::assertSame(CalendarSyncException::NO_CREDENTIAL, $sync->sync($google)->errorCode);
        $this->credentials()->hasToken = true;
        $source->errorCode             = null;
        self::assertNull($sync->sync($google)->errorCode);
        self::assertSame([CalendarConnectionStatus::Active, null], [$google->getStatus(), $google->getLastErrorCode()]);

        $this->connections()->setPaused($this->user, $microsoft->getId(), true);
        self::assertCount(1, $sync->syncUser($this->user, 'org-a'), 'paused connections are not refreshed');
        self::assertCount(0, $sync->syncUser($this->user, 'org-b'), 'local connections are not refreshed');
        $this->assertNoEventData();
    }

    public function testEventsThatAreGoneLoseTheirPendingSuggestion(): void
    {
        $connection = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', '2026-11');
        $source     = new FakeCalendarEventSource(CalendarProvider::Google, [
            $this->event('evt-a', null, '2026-11-04T09:00:00Z', 1800, '#task-1'),
            $this->event('evt-b', null, '2026-11-04T10:00:00Z', 1800, '#task-2'),
            $this->event('evt-c', null, '2026-11-04T11:00:00Z', 1800, '#task-3'),
            $this->event('evt-old', null, '2026-10-20T11:00:00Z', 1800, '#task-3'),
        ]);
        $sync = $this->sync($source);
        self::assertSame(3, $sync->sync($connection)->counts['new'], 'evt-old is before the look-back');
        $repo    = $this->repository();
        [$a, $b] = $repo->findSuggestionsByUser((string) $this->user->getId(), 'org-a', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-07'), [CalendarSuggestionStatus::Pending]);
        $a->dismiss();
        $repo->flushSuggestions();

        // GG-L3: evt-b moved (new occurrence start = new reference), evt-c was cancelled, evt-a (dismissed) is gone too.
        $source->events    = [$this->event('evt-b', null, '2026-11-05T10:00:00Z', 1800, '#task-2')];
        $source->truncated = true;
        $truncated         = $sync->sync($this->reload($connection->getId()));
        self::assertSame([1, 0], [$truncated->counts['new'], $truncated->counts['stale']], 'an incomplete read removes nothing');
        $source->truncated = false;
        $result            = $sync->sync($this->reload($connection->getId()));
        self::assertSame([0, 2], [$result->counts['new'], $result->counts['stale']]);
        $this->entityManager()->clear();
        $left = $repo->findSuggestionsByUser((string) $this->user->getId(), 'org-a', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-07'), CalendarSuggestionStatus::cases());
        self::assertSame([[CalendarSuggestionStatus::Dismissed, '2026-11-04 09:00'], [CalendarSuggestionStatus::Pending, '2026-11-05 10:00']], array_map(static fn (CalendarSuggestion $s): array => [$s->getStatus(), $s->getStartsAt()->format('Y-m-d H:i')], $left), 'decided rows are kept');
    }

    public function testDisabledProvidersNeitherStarveNorRepeat(): void
    {
        $google    = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', 'v1');
        $microsoft = $this->connections()->connect($this->user, 'org-b', CalendarProvider::Microsoft, CalendarConnectionMode::Server, 'host-cred-2', 'v1');
        $repo      = $this->repository();
        $matcher   = self::getContainer()->get(CalendarEventMatcher::class);
        $hours     = self::getContainer()->get(CalendarWorkingHours::class);
        self::assertInstanceOf(CalendarEventMatcher::class, $matcher);
        self::assertInstanceOf(CalendarWorkingHours::class, $hours);
        // The host removed Microsoft from calendar.providers after users connected it.
        $sync = new CalendarSyncService($repo, $repo, $repo, new CalendarSettings(true, ['google']), $hours, $matcher, [new FakeCalendarEventSource()], $this->credentials(), $this->dispatcher, $this->clock, $this->logger);

        self::assertSame(['synced' => 1, 'failed' => 0, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue(null, 1), 'GG-D-1: the disabled provider is not even due');
        self::assertSame([], $repo->findConnectionsDue($this->clock->now()->modify('+1 day'), null, 10, 0, []));
        self::assertSame('not_syncable', $sync->sync($microsoft)->errorCode);
        self::assertSame(['not_syncable', 1, '2026-11-06 14:00'], [$microsoft->getLastErrorCode(), $microsoft->getFailureCount(), $microsoft->getNextAttemptAt()?->format('Y-m-d H:i')], 'recorded with a backoff');
        self::assertNotNull($google->getLastSyncAt());
    }

    public function testTheStaleCleanUpKeepsRowsDecidedMeanwhile(): void
    {
        $connection = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', 'v1');
        $this->sync(new FakeCalendarEventSource(CalendarProvider::Google, [$this->event('evt-a', null, '2026-11-04T09:00:00Z', 1800, '#task-1'), $this->event('evt-b', null, '2026-11-04T10:00:00Z', 1800, '#task-2')]))->sync($connection);
        // A concurrent accept decided evt-a in the database after this process read it as pending.
        $this->entityManager()->getConnection()->executeStatement("UPDATE time_track_calendar_suggestions SET status = 'accepted' WHERE task_id = 'task-1'");

        self::assertSame(1, $this->repository()->deleteUnseenPending($connection->getId(), new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-07'), []));
        self::assertSame(['accepted'], array_values($this->entityManager()->getConnection()->fetchFirstColumn('SELECT status FROM time_track_calendar_suggestions')));
        self::assertSame(0, $this->repository()->deleteUnseenPending($connection->getId(), new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-07'), []));
    }

    public function testFailuresBackOffAndNeverStopTheBatch(): void
    {
        $other  = $this->createUser('other@example.com');
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships[(string) $other->getId()] = ['org-a'];
        $failing                                       = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', 'v1');
        $healthy                                       = $this->connections()->connect($other, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-2', 'v1');
        $credentials                                   = $this->credentials();
        $credentials->failFor                          = [$failing->getId()];
        $sync                                          = $this->sync(new FakeCalendarEventSource(CalendarProvider::Google, []));

        // GG-L5: a host exception is a per-connection failure (internal_error), the batch goes on.
        self::assertSame(['synced' => 1, 'failed' => 1, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue());
        self::assertSame(['internal_error', 1, '2026-11-06 14:00'], [$failing->getLastErrorCode(), $failing->getFailureCount(), $failing->getNextAttemptAt()?->format('Y-m-d H:i')], 'next attempt after 2 × 60 minutes');
        self::assertSame('2026-11-06 12:00', $failing->getLastAttemptAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-11-06 13:00', $healthy->getNextAttemptAt()?->format('Y-m-d H:i'));
        self::assertContains('TimeTrack calendar sync failed.', $this->logger->messages());
        self::assertSame(['internal_error'], array_map(static fn (CalendarSyncResult $r): ?string => $r->errorCode, $sync->syncUser($this->user, 'org-a')), 'refresh=1 never throws');

        $this->clock->modify('+61 minutes');
        self::assertSame(['synced' => 1, 'failed' => 0, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue(), 'the failing one waits for its backoff');
        self::assertSame([2, '2026-11-06 16:00'], [$failing->getFailureCount(), $failing->getNextAttemptAt()?->format('Y-m-d H:i')], 'the refresh failed too: 60 × 2^2 minutes');

        // Least recently attempted first: at 17:01 both are due; with a limit of one only the failing one (12:00) runs.
        $this->clock->modify('+4 hours');
        self::assertSame(['synced' => 0, 'failed' => 1, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue(null, 1));
        self::assertSame([3, '2026-11-07 01:01'], [$failing->getFailureCount(), $failing->getNextAttemptAt()?->format('Y-m-d H:i')]);
        self::assertSame('2026-11-06 13:01', $healthy->getLastAttemptAt()?->format('Y-m-d H:i'));
        self::assertSame(['synced' => 1, 'failed' => 0, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue(null, 1));

        // Rows outside working hours do not use the limit; a reconnect clears the backoff.
        $credentials->failFor = [];
        $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-3', 'v2');
        self::assertSame([0, null], [$failing->getFailureCount(), $failing->getNextAttemptAt()]);
        $profile = self::getContainer()->get(MutableProfileProvider::class);
        self::assertInstanceOf(MutableProfileProvider::class, $profile);
        $profile->hoursByUser[(string) $this->user->getId()] = null;
        $this->clock->modify('+1 day');
        // The healthy one is attempted last, so the failing one (outside hours now) comes first in the due order.
        $sync->sync($healthy);
        $this->clock->modify('+61 minutes');
        self::assertSame(['synced' => 1, 'failed' => 0, 'outside_hours' => 1, 'purged' => 0], $sync->syncDue(null, 1));
    }

    public function testTheScheduledRunRespectsWorkingHoursAndPurges(): void
    {
        $connection = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', '2026-11');
        $source     = new FakeCalendarEventSource(CalendarProvider::Google, [$this->event('evt-a', null, '2026-11-04T09:00:00Z', 1800, '#task-1')]);
        $sync       = $this->sync($source);
        $profile    = self::getContainer()->get(MutableProfileProvider::class);
        self::assertInstanceOf(MutableProfileProvider::class, $profile);
        $profile->hours = ['timezone' => 'Europe/Madrid', 'slots' => [['day' => 5, 'from' => '08:00', 'to' => '12:00']]];

        self::assertSame(['synced' => 0, 'failed' => 0, 'outside_hours' => 1, 'purged' => 0], $sync->syncDue(), 'Friday 13:00 Madrid is outside');
        self::assertSame([], $source->calls);

        $this->clock->modify('-3 hours');
        self::assertSame(['synced' => 1, 'failed' => 0, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue((string) $this->user->getId()));
        self::assertSame(['synced' => 0, 'failed' => 0, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue(), 'not due again within the interval');
        $this->clock->modify('+61 minutes');
        $source->errorCode = CalendarSyncException::TOKEN_EXPIRED;
        self::assertSame(['synced' => 0, 'failed' => 1, 'outside_hours' => 0, 'purged' => 0], $sync->syncDue());
        self::assertSame(CalendarConnectionStatus::Error, $connection->getStatus());
        $this->clock->modify('+20 days');
        $profile->hours = null;
        self::assertSame(['synced' => 0, 'failed' => 0, 'outside_hours' => 1, 'purged' => 1], $sync->syncDue(), 'unknown hours are never inside; expired rows are purged');
        self::assertContains('TimeTrack calendar sync run finished.', $this->logger->messages());
    }

    public function testConnectionsAreOptInOwnerOnlyAndRevocable(): void
    {
        $service = $this->connections();
        $other   = $this->createUser('other@example.com');
        foreach ([
            'invalid_scope'           => fn (): CalendarConnection => $service->connect($this->user, 'org-z', CalendarProvider::Google, CalendarConnectionMode::Server, 'c', 'v1'),
            'invalid_credential_ref'  => fn (): CalendarConnection => $service->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, null, 'v1'),
            'invalid_credential_ref ' => fn (): CalendarConnection => $service->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Local, 'c', 'v1'),
            ' invalid_credential_ref' => fn (): CalendarConnection => $service->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, "bad\nref", 'v1'),
            'invalid_consent_version' => fn (): CalendarConnection => $service->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'c', 'version 1 with spaces'),
        ] as $code => $operation) {
            try {
                $operation();
                self::fail('expected ' . $code);
            } catch (CalendarException $exception) {
                self::assertSame(trim($code), $exception->errorCode);
            }
        }

        $first  = $service->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', 'v1');
        $second = $service->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-2', 'v2');
        self::assertSame($first->getId(), $second->getId(), 'one connection per user, scope and provider');
        self::assertSame(['host-cred-2', 'v2'], [$second->getCredentialRef(), $second->getConsentVersion()]);
        self::assertSame(['id', 'provider', 'mode', 'status', 'scopeKey', 'lastSyncAt', 'lastErrorCode', 'consentVersion', 'consentedAt'], array_keys($second->toArray()), 'no credential reference in the client shape');
        self::assertSame([$first->getId()], array_map(static fn (CalendarConnection $c): string => $c->getId(), $service->connectionsOf($this->user, 'org-a')));
        self::assertSame([], $service->connectionsOf($other));
        self::assertNull($service->connectionOf($other, $first->getId()), 'another user cannot see it');
        self::assertNull($service->connectionOf($this->user, str_repeat('x', 37)));
        self::assertFalse($service->setPaused($other, $first->getId(), true));
        self::assertTrue($service->setPaused($this->user, $first->getId(), true));
        self::assertTrue($service->setPaused($this->user, $first->getId(), true), 'idempotent');
        self::assertSame(CalendarConnectionStatus::Paused, $first->getStatus());
        self::assertTrue($service->setPaused($this->user, $first->getId(), false));
        self::assertSame(['google' => 'https://beacon.example/account/calendars/connect/google', 'microsoft' => null], $service->connectUrls($this->user, 'org-a'), 'only https links leave');

        $this->rules()->create($this->user, 'org-a', 'daily', 'task-1');
        $this->rules()->create($this->user, 'org-b', 'daily', 'task-1');
        $this->sync(new FakeCalendarEventSource(CalendarProvider::Google, [$this->event('evt-a', null, '2026-11-04T09:00:00Z', 1800, '#task-1')]))->sync($first);
        self::assertFalse($service->revoke($other, $first->getId()));
        self::assertTrue($service->revoke($this->user, $first->getId()));
        self::assertSame([$first->getId()], $this->credentials()->revoked, 'the host revokes the token and the grant');
        self::assertSame([], $this->rows('time_track_calendar_connections'));
        self::assertSame([], $this->rows('time_track_calendar_suggestions'));
        self::assertSame(['org-b'], array_column($this->rows('time_track_calendar_rules'), 'scope_key'), 'rules of other scopes stay');
        self::assertFalse($service->revoke($this->user, $first->getId()));

        $local = $service->connect($this->user, 'org-b', CalendarProvider::Microsoft, CalendarConnectionMode::Local, null, 'v1');
        self::assertSame(1, $service->revokeAllFor($this->user), 'GG-I8: account erasure in one call');
        self::assertSame(0, $service->revokeAllFor($this->user));
        self::assertCount(1, $this->credentials()->revoked, 'local mode has no host token');
        self::assertFalse($service->revoke($this->user, $local->getId()));
        $changes = array_map(static fn (CalendarConnectionChangedEvent $e): string => ($e->from ?? 'new') . '>' . $e->to, array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof CalendarConnectionChangedEvent)));
        self::assertSame(['new>active', 'active>active', 'active>paused', 'paused>active', 'active>revoked', 'new>active', 'active>revoked'], $changes);
    }

    public function testSettingsLimitProvidersModesAndTheFeature(): void
    {
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $narrow = new CalendarConnectionService($this->repository(), new CalendarSettings(true, ['google'], ['server']), $scopes);
        $off    = new CalendarConnectionService($this->repository(), new CalendarSettings(false), $scopes);
        $noHost = new CalendarConnectionService($this->repository(), new CalendarSettings(true));
        foreach ([
            'provider_not_allowed' => fn (): CalendarConnection => $narrow->connect($this->user, 'org-a', CalendarProvider::Microsoft, CalendarConnectionMode::Server, 'c', 'v1'),
            'mode_not_allowed'     => fn (): CalendarConnection => $narrow->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Local, null, 'v1'),
            'calendar_disabled'    => fn (): CalendarConnection => $off->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'c', 'v1'),
        ] as $code => $operation) {
            try {
                $operation();
                self::fail('expected ' . $code);
            } catch (CalendarException $exception) {
                self::assertSame($code, $exception->errorCode);
            }
        }
        self::assertSame('org-a', $narrow->scopeOf($this->user, null)?->key);
        self::assertNull($off->scopeOf($this->user, null));
        self::assertFalse($noHost->isEnabled(), 'no scope provider');
        self::assertSame([], $off->connectionsOf($this->user));
        self::assertSame(['google' => null], $narrow->connectUrls($this->user, 'org-a'), 'no host credential provider');
        $connection = $narrow->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'c', 'v1');
        self::assertTrue($narrow->revoke($this->user, $connection->getId()), 'without a host credential provider');
    }

    public function testRulesAreValidatedAndOwnerOnly(): void
    {
        $rules = $this->rules();
        $other = $this->createUser('other@example.com');
        foreach ([
            'invalid_scope'     => fn (): CalendarRule => $rules->create($this->user, 'org-z', 'daily', 'task-1'),
            'invalid_pattern'   => fn (): CalendarRule => $rules->create($this->user, 'org-a', "   \n ", 'task-1'),
            ' invalid_pattern'  => fn (): CalendarRule => $rules->create($this->user, 'org-a', str_repeat('a', 121), 'task-1'),
            '  invalid_pattern' => fn (): CalendarRule => $rules->create($this->user, 'org-a', "daily\u{202E}", 'task-1'),
            'not_trackable'     => fn (): CalendarRule => $rules->create($this->user, 'org-a', 'daily', 'task-404'),
            ' not_trackable'    => fn (): CalendarRule => $rules->create($this->user, 'org-a', 'daily', ''),
        ] as $code => $operation) {
            try {
                $operation();
                self::fail('expected ' . $code);
            } catch (CalendarException $exception) {
                self::assertSame(trim($code), $exception->errorCode);
            }
        }
        $rule = $rules->create($this->user, 'org-a', "Daily \t stand-up", 'task-1', false);
        self::assertSame(['Daily stand-up', false], [$rule->getPattern(), $rule->groupsMatches()]);
        self::assertSame(['id', 'scopeKey', 'pattern', 'taskId', 'groupMatches', 'createdAt'], array_keys($rule->toArray()));
        $rules->create($this->user, 'org-a', 'retro', 'task-2');
        $rules->create($this->user, 'org-a', 'sync', 'task-3');
        try {
            $rules->create($this->user, 'org-a', 'planning', 'task-3');
            self::fail('expected too_many_rules');
        } catch (CalendarException $exception) {
            self::assertSame('too_many_rules', $exception->errorCode);
        }
        self::assertCount(3, $rules->rulesOf($this->user, 'org-a'));
        self::assertSame([], $rules->rulesOf($other, 'org-a'));
        self::assertFalse($rules->delete($other, $rule->getId()));
        self::assertFalse($rules->delete($this->user, 'missing'));
        self::assertTrue($rules->delete($this->user, $rule->getId()));
        self::assertCount(2, $rules->rulesOf($this->user, 'org-a'));
    }

    public function testTheSyncCommandPrintsCountsOnly(): void
    {
        $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', 'v1');
        $command = self::getContainer()->get(CalendarSyncCommand::class);
        self::assertInstanceOf(CalendarSyncCommand::class, $command);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--user' => (string) $this->user->getId(), '--limit' => '10']));
        self::assertStringContainsString('Synced 0 connection(s), 1 failed, 0 outside working hours; purged 0 expired suggestion(s).', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()), 'the fake HTTP client answers 503');
        $connection = $this->repository()->findConnectionsByUser((string) $this->user->getId())[0];
        self::assertSame('provider_error', $connection->getLastErrorCode());
    }

    public function testTheStoreQueries(): void
    {
        $connection = $this->connections()->connect($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred-1', 'v1');
        $this->sync(new FakeCalendarEventSource(CalendarProvider::Google, $this->dailies('evt-daily', 'series-daily', '#task-1')))->sync($connection);
        $repo   = $this->repository();
        $userId = (string) $this->user->getId();
        $all    = $repo->findSuggestionsByUser($userId, null, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-08'), [CalendarSuggestionStatus::Pending]);

        self::assertCount(5, $all);
        self::assertSame([], $repo->findSuggestionsByUser($userId, null, new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-08'), []));
        self::assertSame([], $repo->findSuggestionsByUser($userId, 'org-b', new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-08'), [CalendarSuggestionStatus::Pending]));
        self::assertSame([], $repo->findSuggestionsByIds($userId, []));
        self::assertSame([], $repo->findSuggestionsByIds((string) $this->createUser('other@example.com')->getId(), [$all[0]->getId()]), 'owner only');
        $group = $repo->findSuggestionsByGroup($userId, (string) $all[0]->getGroupKey());
        self::assertSame(array_map(static fn (CalendarSuggestion $s): string => $s->getId(), $all), array_map(static fn (CalendarSuggestion $s): string => $s->getId(), $group));
        self::assertSame([], $repo->findSuggestionsByGroup($userId, 'series:none:2026-W45'));
        self::assertSame($connection->getId(), $repo->findConnectionFor($userId, 'org-a', CalendarProvider::Google)?->getId());
        self::assertNull($repo->findConnectionFor($userId, 'org-a', CalendarProvider::Microsoft));
    }

    public function testTheReferenceMigrationsMatchTheMapping(): void
    {
        $em       = $this->entityManager();
        $conn     = $em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $name     = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            $platform instanceof SQLitePlatform        => 'sqlite',
            default                                    => throw new LogicException('Unsupported test platform.'),
        };
        $tool     = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        $this->runSql($name . '-down.sql');
        $expected113 = $tool->getSchemaFromMetadata($metadata);
        foreach (['time_track_calendar_suggestions', 'time_track_calendar_rules', 'time_track_calendar_connections'] as $table) {
            $expected113->dropTable($table);
        }
        $schemaManager = $conn->createSchemaManager();
        $diff          = $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $expected113);
        self::assertSame([], $platform->getAlterSchemaSQL($diff), 'the down script restores the 1.13 schema');

        $this->runSql($name . '-up.sql');
        self::assertSame([], $tool->getUpdateSchemaSql($metadata), 'the up script is exactly the 1.14 mapping');
    }

    public function testTheDemoMigrationAndUpgradingCarryTheReferenceStatements(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__, 2) . '/demo/symfony8/migrations/Version20261107000000.php');
        $upgrading = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/UPGRADING.md');
        foreach (['mysql', 'postgresql', 'sqlite'] as $platform) {
            foreach (['up', 'down'] as $direction) {
                foreach ($this->statements($platform . '-' . $direction . '.sql') as $statement) {
                    self::assertStringContainsString($statement . ';', $upgrading);
                    if ($platform === 'mysql') {
                        self::assertStringContainsString("addSql('" . $statement . "')", $migration);
                    }
                }
            }
        }
    }

    /**
     * @return list<CalendarEventView>
     */
    private function dailies(string $id, string $series, string $title): array
    {
        $events = [];
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $day) {
            $events[] = $this->event($id . '_' . $day, $series, $day . 'T08:00:00Z', 900, $title);
        }

        return $events;
    }

    private function event(string $id, ?string $series, string $start, int $seconds, string $title): CalendarEventView
    {
        $from = new DateTimeImmutable($start);

        return new CalendarEventView($id, $series, $title, $from, $from->modify('+' . $seconds . ' seconds'));
    }

    private function sync(FakeCalendarEventSource $source): CalendarSyncService
    {
        $matcher = self::getContainer()->get(CalendarEventMatcher::class);
        self::assertInstanceOf(CalendarEventMatcher::class, $matcher);
        $settings = self::getContainer()->get(CalendarSettings::class);
        self::assertInstanceOf(CalendarSettings::class, $settings);
        $hours = self::getContainer()->get(CalendarWorkingHours::class);
        self::assertInstanceOf(CalendarWorkingHours::class, $hours);
        $repo = $this->repository();

        return new CalendarSyncService($repo, $repo, $repo, $settings, $hours, $matcher, [$source], $this->credentials(), $this->dispatcher, $this->clock, $this->logger);
    }

    private function reload(string $connectionId): CalendarConnection
    {
        $connection = $this->repository()->findConnection($connectionId);
        self::assertInstanceOf(CalendarConnection::class, $connection);

        return $connection;
    }

    private function connections(): CalendarConnectionService
    {
        $settings = self::getContainer()->get(CalendarSettings::class);
        self::assertInstanceOf(CalendarSettings::class, $settings);
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);

        return new CalendarConnectionService($this->repository(), $settings, $scopes, $this->credentials(), $this->dispatcher, $this->clock);
    }

    private function rules(): CalendarRuleService
    {
        $service = self::getContainer()->get(CalendarRuleService::class);
        self::assertInstanceOf(CalendarRuleService::class, $service);

        return $service;
    }

    private function repository(): DoctrineOrmCalendarRepository
    {
        return new DoctrineOrmCalendarRepository($this->entityManager());
    }

    private function credentials(): StubCalendarCredentialProvider
    {
        $credentials = self::getContainer()->get(StubCalendarCredentialProvider::class);
        self::assertInstanceOf(StubCalendarCredentialProvider::class, $credentials);

        return $credentials;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager()->getConnection()->fetchAllAssociative('SELECT * FROM ' . $table);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function rowOf(array $rows, string $column, string $value): array
    {
        foreach ($rows as $row) {
            if ($row[$column] === $value) {
                return $row;
            }
        }

        throw new LogicException('row not found');
    }

    /**
     * AC-14: no title, provider id or series id in any calendar row, log record, dispatched event or exception.
     */
    private function assertNoEventData(): void
    {
        $haystack = json_encode([
            $this->rows('time_track_calendar_connections'),
            $this->rows('time_track_calendar_suggestions'),
            $this->rows('time_track_calendar_rules'),
            $this->logger->records,
            array_map(serialize(...), $this->events),
        ]);
        foreach ([self::MARKER, 'evt-', 'series-', 'Daily', 'Review', 'Dentist'] as $needle) {
            self::assertStringNotContainsString($needle, (string) $haystack);
        }
    }

    private function runSql(string $file): void
    {
        $conn = $this->entityManager()->getConnection();
        foreach ($this->statements($file) as $statement) {
            $conn->executeStatement(str_replace('REFERENCES users (id)', 'REFERENCES app_user (id)', $statement));
        }
    }

    /**
     * @return list<string>
     */
    private function statements(string $file): array
    {
        $statements = [];
        foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2) . '/docs/sql/1.14.0/' . $file)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '--')) {
                $statements[] = rtrim($line, ';');
            }
        }

        return $statements;
    }
}
