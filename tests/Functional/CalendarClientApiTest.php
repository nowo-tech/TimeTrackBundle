<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarMatchKind;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\CalendarSuggestionDecidedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Tests\Support\TimesheetFixtures;
use RuntimeException;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_fill;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function hash;
use function str_repeat;
use function substr;
use function urlencode;

use const DATE_ATOM;

/**
 * Since 1.14 (contract §6.4–§6.6, §10.3, AC-02 for the new paths, AC-16, AC-17): the calendar routes and `POST
 * entries` on the client API — scopes, owner-only access, grouping, per-occurrence accept with lock / overlap / track
 * checks, dismiss, `resolve-keys`, idempotency and the `GET me` flags.
 */
final class CalendarClientApiTest extends FunctionalTestCase
{
    use ClockSensitiveTrait;

    /** @var list<object> */
    private array $events = [];

    private int $refs = 0;

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'calendar'       => ['enabled' => true, 'task_key_resolver' => StubTaskKeyResolver::class, 'credential_provider' => StubCalendarCredentialProvider::class],
            'manual_entries' => ['enabled' => true],
            'client_api'     => ['profile_provider' => MutableProfileProvider::class, 'rate_limit' => ['per_token' => 0]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a', 'org-b']];
        $this->events        = [];
        $dispatcher          = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([TimeTrackEvents::TIME_ENTRY_CREATED, TimeTrackEvents::CALENDAR_SUGGESTION_DECIDED] as $name) {
            $dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testOldTokensDoNotGetTheNewScopes(): void
    {
        $token = $this->login();
        foreach ([['GET', '/api/time-track/calendar/connections'], ['GET', '/api/time-track/suggestions'], ['POST', '/api/time-track/suggestions/accept'],
            ['POST', '/api/time-track/suggestions/dismiss'], ['POST', '/api/time-track/suggestions/resolve-keys'], ['POST', '/api/time-track/entries'],
            ['DELETE', '/api/time-track/calendar/connections/x']] as [$method, $uri]) {
            $body = $this->api($method, $uri, [], $token);
            self::assertResponseStatusCodeSame(403, $method . ' ' . $uri);
            self::assertSame('insufficient_scope', $body['error'] ?? null);
        }
        $only = $this->token(ClientScope::SuggestionsWrite);
        $this->api('POST', '/api/time-track/suggestions/accept', ['ids' => ['x']], $only);
        self::assertResponseStatusCodeSame(403, 'accept also needs entries:write');
        $this->api('OPTIONS', '/api/time-track/suggestions', null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('GET, POST, DELETE, OPTIONS', $this->client->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('Authorization, Content-Type, Idempotency-Key', $this->client->getResponse()->headers->get('Access-Control-Allow-Headers'), 'GG-I6');
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'), 'GG-I1');
    }

    public function testMeFlagsAndConnections(): void
    {
        $me = $this->api('GET', '/api/time-track/me', null, $this->login());
        self::assertTrue($me['features']['calendar_suggestions'] ?? null);
        self::assertTrue($me['features']['manual_entries'] ?? null);

        $connection = $this->connect();
        $token      = $this->token(ClientScope::SuggestionsRead, ClientScope::SuggestionsWrite);
        $list       = $this->api('GET', '/api/time-track/calendar/connections', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame('org-a', $list['scopeKey'] ?? null);
        self::assertSame([$connection->getId()], array_column($list['connections'] ?? [], 'id'));
        self::assertArrayNotHasKey('credentialRef', $list['connections'][0] ?? []);
        self::assertSame(['google' => 'https://beacon.example/account/calendars/connect/google', 'microsoft' => null], $list['connectUrls'] ?? null);
        self::assertSame([], $this->api('GET', '/api/time-track/calendar/connections?scope=org-b', null, $token)['connections'] ?? null);
        $this->api('GET', '/api/time-track/calendar/connections?scope=org-z', null, $token);
        self::assertResponseStatusCodeSame(404);

        $other = $this->createUser('other@example.com');
        $this->api('DELETE', '/api/time-track/calendar/connections/' . $connection->getId(), null, $this->tokenFor($other, ClientScope::SuggestionsWrite));
        self::assertResponseStatusCodeSame(404, 'another user\'s connection looks like an unknown one');
        $this->api('DELETE', '/api/time-track/calendar/connections/' . $connection->getId(), null, $token);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', '/api/time-track/suggestions?scope=org-z', null, $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuggestionsAreGroupedAndAcceptedPerOccurrence(): void
    {
        $connection = $this->connect();
        $lockedWeek = $this->day(10, 9)->format('o-\WW');
        $this->entityManager()->persist(TimesheetFixtures::locked($this->managedUser(), 'org-a', $lockedWeek));
        $daily = [];
        foreach ([3, 2, 1] as $daysAgo) {
            $daily[] = $this->suggestion($connection, 'task-1', $this->day($daysAgo, 8), 900, 'series:aaaaaaaaaaaaaaaa:W');
        }
        $locked  = $this->suggestion($connection, 'task-1', $this->day(10, 9), 1800, null);
        $overlap = $this->suggestion($connection, 'task-2', $this->day(2, 8), 600, null);
        $this->entityManager()->flush();
        $token = $this->token(ClientScope::SuggestionsRead, ClientScope::SuggestionsWrite, ClientScope::EntriesWrite);

        $list = $this->api('GET', '/api/time-track/suggestions', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'), 'GG-I1');
        self::assertIsArray($list);
        self::assertArrayHasKey('ignored', $list);
        self::assertNull($list['ignored']);
        self::assertCount(1, $list['groups'] ?? []);
        self::assertSame(['series:aaaaaaaaaaaaaaaa:W', 'task-1', 2700], [$list['groups'][0]['groupKey'], $list['groups'][0]['taskId'], $list['groups'][0]['totalSeconds']]);
        self::assertSame(array_map(static fn (CalendarSuggestion $s): string => $s->getId(), $daily), array_column($list['groups'][0]['occurrences'], 'id'));
        self::assertSame([$locked->getId(), $overlap->getId()], array_map(static fn (array $single): string => $single['occurrences'][0]['id'], $list['singles'] ?? []));
        $this->api('GET', '/api/time-track/suggestions?from=yesterday', null, $token);
        self::assertResponseStatusCodeSame(400);

        $ids      = [...array_map(static fn (CalendarSuggestion $s): string => $s->getId(), $daily), $locked->getId(), $overlap->getId(), 'unknown-id'];
        $accepted = $this->api('POST', '/api/time-track/suggestions/accept', ['ids' => $ids, 'note' => 'from the calendar'], $token);
        self::assertResponseIsSuccessful();
        $errors = array_map(static fn (array $o): ?string => $o['error'] ?? null, $accepted['outcomes'] ?? []);
        self::assertSame([null, null, null, 'period_locked', 'overlap', 'not_found'], $errors, 'AC-16: the locked occurrence and the overlap are reported, the rest is created');
        $entry = $this->entityManager()->find(TimeEntry::class, $accepted['outcomes'][0]['entryId'] ?? '');
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame([TimeEntrySource::Calendar, ['origin' => 'calendar', 'provider' => 'google', 'note' => 'from the calendar'], 'org-a'], [$entry->getSource(), $entry->getMetadata(), $entry->getScopeKey()]);
        self::assertCount(3, array_filter($this->events, static fn (object $e): bool => $e instanceof TimeEntryCreatedEvent && $e->origin === 'calendar'));
        $decided = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof CalendarSuggestionDecidedEvent));
        self::assertSame(['accepted', 3, 3], [$decided[0]->decision, count($decided[0]->suggestionIds), count($decided[0]->entryIds)]);

        $again = $this->api('POST', '/api/time-track/suggestions/accept', ['ids' => [$daily[0]->getId()]], $token);
        self::assertSame('not_found', $again['outcomes'][0]['error'] ?? null, 'an accepted suggestion cannot be accepted twice');
        $remaining = $this->api('GET', '/api/time-track/suggestions', null, $token);
        self::assertSame([], $remaining['groups'] ?? null);
        self::assertCount(2, $remaining['singles'] ?? []);

        $refused = $this->api('POST', '/api/time-track/suggestions/accept', ['ids' => [$overlap->getId()], 'taskId' => 'task-404'], $token);
        self::assertSame([['id' => $overlap->getId(), 'error' => 'not_trackable']], $refused['outcomes'] ?? null);
        $byGroup = $this->api('POST', '/api/time-track/suggestions/accept', ['groupKey' => 'series:none:W', 'taskId' => 'task-404'], $token);
        self::assertSame([], $byGroup['outcomes'] ?? null);
        foreach ([[], ['ids' => []], ['ids' => ['a'], 'groupKey' => 'g'], ['ids' => [1]], ['ids' => [str_repeat('x', 37)]], ['groupKey' => str_repeat('g', 49)], ['ids' => ['a'], 'taskId' => 5], ['ids' => ['a'], 'note' => ['x']], ['ids' => ['k' => 'a']]] as $bad) {
            $this->api('POST', '/api/time-track/suggestions/accept', $bad, $token);
            self::assertResponseStatusCodeSame(400);
        }

        $dismissed = $this->api('POST', '/api/time-track/suggestions/dismiss', ['ids' => [$overlap->getId(), $daily[0]->getId(), 'unknown-id']], $token);
        self::assertSame([$overlap->getId()], $dismissed['dismissed'] ?? null);
        $this->api('POST', '/api/time-track/suggestions/dismiss', ['groupKey' => ''], $token);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->api('POST', '/api/time-track/suggestions/dismiss', ['groupKey' => 'series:aaaaaaaaaaaaaaaa:W'], $token)['dismissed'] ?? null, 'already accepted');
    }

    public function testAcceptIsSerialisedAndAtomicPerOccurrence(): void
    {
        $connection = $this->connect();
        $raced      = $this->suggestion($connection, 'task-1', $this->day(3, 8), 900, null);
        $broken     = $this->suggestion($connection, 'task-3', $this->day(3, 10), 900, null);
        $fine       = $this->suggestion($connection, 'task-2', $this->day(3, 12), 900, null);
        $dismissed  = $this->suggestion($connection, 'task-2', $this->day(2, 12), 900, null);
        $this->entityManager()->flush();
        $token = $this->token(ClientScope::SuggestionsWrite, ClientScope::EntriesWrite);
        // A concurrent request decided two of them after this one read them (the database changed, not this request's copy).
        $this->entityManager()->getConnection()->executeStatement("UPDATE time_track_calendar_suggestions SET status = 'accepted' WHERE id IN (?, ?)", [$raced->getId(), $dismissed->getId()]);
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->failForTasks = ['task-3'];
        $dispatcher           = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::TIME_ENTRY_CREATED, static function (): never {
            throw new RuntimeException('listener down');
        });
        $dispatcher->addListener(TimeTrackEvents::CALENDAR_SUGGESTION_DECIDED, static function (): never {
            throw new RuntimeException('decided listener down');
        });

        $accepted = $this->api('POST', '/api/time-track/suggestions/accept', ['ids' => [$raced->getId(), $broken->getId(), $fine->getId()]], $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['not_found', 'internal_error', null], array_map(static fn (array $o): ?string => $o['error'] ?? null, $accepted['outcomes'] ?? []), 'GG-L1: the raced one is not accepted twice; GG-L2: a failure is per occurrence');
        $this->entityManager()->clear();
        $rows = $this->entityManager()->getConnection()->fetchAllKeyValue('SELECT id, status FROM time_track_calendar_suggestions');
        self::assertSame(['pending', 'accepted'], [$rows[$broken->getId()], $rows[$fine->getId()]], 'the failed occurrence left nothing behind');
        self::assertSame(1, (int) $this->entityManager()->getConnection()->fetchOne("SELECT COUNT(*) FROM time_track_entries WHERE source = 'calendar'"));
        $decided = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof CalendarSuggestionDecidedEvent));
        self::assertSame([[$fine->getId()]], array_map(static fn (CalendarSuggestionDecidedEvent $e): array => $e->suggestionIds, $decided), 'dispatched after the commits, even with a failing entry listener');
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertContains('TimeTrack entry-created listener failed.', $logger->messages());
        self::assertContains('TimeTrack suggestion accept failed.', $logger->messages());
        self::assertContains('TimeTrack suggestion-decided listener failed.', $logger->messages(), 'delta GG-D-4: the committed decision is still a 200');

        $this->api('POST', '/api/time-track/suggestions/dismiss', ['ids' => [$dismissed->getId()]], $token);
        self::assertSame([], $this->api('POST', '/api/time-track/suggestions/dismiss', ['ids' => [$dismissed->getId()]], $token)['dismissed'] ?? null, 'a decided row is not dismissed');
    }

    public function testAGroupCanBeDismissedAndRefreshIsRateLimited(): void
    {
        $connection = $this->connect();
        $this->suggestion($connection, 'task-1', $this->day(2, 8), 900, 'rule:abc:W');
        $this->suggestion($connection, 'task-1', $this->day(1, 8), 900, 'rule:abc:W');
        $this->entityManager()->flush();
        $token = $this->token(ClientScope::SuggestionsRead, ClientScope::SuggestionsWrite);

        $dismissed = $this->api('POST', '/api/time-track/suggestions/dismiss', ['groupKey' => 'rule:abc:W'], $token);
        self::assertCount(2, $dismissed['dismissed'] ?? []);
        $refreshed = $this->api('GET', '/api/time-track/suggestions?refresh=1&from=' . urlencode($this->day(40, 0)->format(DATE_ATOM)), null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $refreshed['ignored'] ?? null, 'the fake HTTP client fails: nothing fetched');
        self::assertSame($this->day(14, 0)->format('Y-m-d'), substr((string) ($refreshed['from'] ?? ''), 0, 10), 'from is clamped to the look-back');
        $this->api('GET', '/api/time-track/suggestions?refresh=1', null, $token);
        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testResolveKeys(): void
    {
        self::mockTime();
        $token  = $this->token(ClientScope::SuggestionsRead);
        $result = $this->api('POST', '/api/time-track/suggestions/resolve-keys', ['keys' => ['#task-1', '#task-9', '#cafebabe', '#task-1']], $token);
        self::assertResponseIsSuccessful();
        self::assertSame([
            '#task-1' => ['taskId' => 'task-1', 'title' => 'Implement login API', 'boardId' => 'board-1', 'trackable' => true],
        ], $result['matches'] ?? null);
        self::assertSame('{"matches":{}}', $this->rawApi('POST', '/api/time-track/suggestions/resolve-keys', ['keys' => ['#none']], $token));
        $this->api('POST', '/api/time-track/suggestions/resolve-keys', ['keys' => ['#task-1'], 'scopeKey' => 'org-z'], $token);
        self::assertResponseStatusCodeSame(404);
        foreach ([[], ['keys' => []], ['keys' => [str_repeat('k', 33)]], ['keys' => [1]], ['keys' => array_fill(0, 51, 'k')], ['keys' => ['a' => 'b']]] as $bad) {
            $this->api('POST', '/api/time-track/suggestions/resolve-keys', $bad, $token);
            self::assertResponseStatusCodeSame(400);
        }
        // The clock is frozen, so the limiter's fixed window cannot end here: exactly 60 valid requests per token (the limiter runs after validation; two were made above).
        for ($used = 2; $used < 60; ++$used) {
            $this->api('POST', '/api/time-track/suggestions/resolve-keys', ['keys' => ['#task-2']], $token);
            self::assertResponseIsSuccessful();
        }
        $this->api('POST', '/api/time-track/suggestions/resolve-keys', ['keys' => ['#task-2']], $token);
        self::assertResponseStatusCodeSame(429);
    }

    public function testPostEntries(): void
    {
        $token = $this->token(ClientScope::EntriesWrite);
        $start = $this->day(4, 9);
        $body  = ['taskId' => 'task-1', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+90 minutes')->format('Y-m-d\TH:i:s.uP'), 'source' => 'manual', 'note' => "  Fixed\u{202E} it  "];

        $this->api('POST', '/api/time-track/entries', $body, $token);
        self::assertResponseStatusCodeSame(400, 'Idempotency-Key is required');
        $created = $this->api('POST', '/api/time-track/entries', $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['task-1', 'manual', 5400, false, 'org-a'], [$created['entry']['taskId'] ?? null, $created['entry']['source'] ?? null, $created['entry']['durationSeconds'] ?? null, $created['entry']['locked'] ?? null, $created['entry']['scopeKey'] ?? null]);
        self::assertSame(['origin' => 'manual', 'note' => 'Fixed it'], $created['entry']['metadata'] ?? null);
        self::assertSame($created, $this->api('POST', '/api/time-track/entries', $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-1']), 'replayed');
        self::assertResponseStatusCodeSame(201);
        $this->api('POST', '/api/time-track/entries', ['source' => 'calendar'] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);
        self::assertResponseStatusCodeSame(422);

        $cases = [
            'overlap'         => [409, ['startedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'endedAt' => $start->modify('+2 hours')->format(DATE_ATOM)]],
            'entry_in_future' => [422, ['startedAt' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM), 'endedAt' => (new DateTimeImmutable('+2 hours'))->format(DATE_ATOM)]],
            'entry_too_long'  => [422, ['startedAt' => $this->day(6, 0)->format(DATE_ATOM), 'endedAt' => $this->day(5, 1)->format(DATE_ATOM)]],
            'invalid_range'   => [422, ['startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->format(DATE_ATOM)]],
            'entry_too_old'   => [422, ['startedAt' => $this->day(40, 9)->format(DATE_ATOM), 'endedAt' => $this->day(40, 10)->format(DATE_ATOM)]],
            'task_forbidden'  => [403, ['taskId' => 'task-404']],
            'invalid_request' => [400, ['source' => 'web']],
        ];
        $i = 0;
        foreach ($cases as $code => [$status, $change]) {
            $answer = $this->api('POST', '/api/time-track/entries', $change + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'case-' . ++$i]);
            self::assertResponseStatusCodeSame($status, $code);
            self::assertSame($code, $answer['error'] ?? null);
        }
        foreach ([['startedAt' => '2026-11-01'], ['taskId' => ''], ['note' => 5], ['endedAt' => '2026-11-01T10:00:00+15:00']] as $bad) {
            $this->api('POST', '/api/time-track/entries', $bad + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'bad-' . ++$i]);
            self::assertResponseStatusCodeSame(400);
        }

        // Locked and submitted periods (AC-02 for POST entries).
        $old = $this->day(10, 9);
        $this->entityManager()->persist(TimesheetFixtures::locked($this->managedUser(), 'org-a', $old->format('o-\WW')));
        $this->entityManager()->persist(TimesheetFixtures::submitted($this->managedUser(), 'org-b', $old->format('o-\WW')));
        $this->entityManager()->flush();
        $locked = $this->api('POST', '/api/time-track/entries', ['startedAt' => $old->format(DATE_ATOM), 'endedAt' => $old->modify('+1 hour')->format(DATE_ATOM)] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'lock-1']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('period_locked', $locked['error'] ?? null);
        self::assertArrayHasKey('lockedThrough', $locked);
        $submitted = $this->api('POST', '/api/time-track/entries', ['taskId' => 'task-3', 'startedAt' => $old->format(DATE_ATOM), 'endedAt' => $old->modify('+1 hour')->format(DATE_ATOM)] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'lock-2']);
        self::assertSame('period_submitted', $submitted['error'] ?? null);

        // A running timer overlaps anything that ends after its start.
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-2', 'startedAt' => (new DateTimeImmutable('-30 minutes'))->format(DATE_ATOM)], $this->token(ClientScope::TimerWrite));
        self::assertResponseStatusCodeSame(201);
        $running = $this->api('POST', '/api/time-track/entries', ['startedAt' => (new DateTimeImmutable('-20 minutes'))->format(DATE_ATOM), 'endedAt' => (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM)] + $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'timer-1']);
        self::assertSame('overlap', $running['error'] ?? null);
        $manual = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof TimeEntryCreatedEvent && $e->origin === 'manual'));
        self::assertCount(1, $manual);
        self::assertSame($created['entry']['id'] ?? null, $manual[0]->getEntry()->getId());

        // 120 entries per hour per token (the earlier calls count).
        for ($i = 0; $i < 120; ++$i) {
            $this->api('POST', '/api/time-track/entries', $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'flood-' . $i]);
        }
        self::assertResponseStatusCodeSame(429);
    }

    private function connect(): CalendarConnection
    {
        $service = self::getContainer()->get(CalendarConnectionService::class);
        self::assertInstanceOf(CalendarConnectionService::class, $service);

        return $service->connect($this->managedUser(), 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'host-cred', 'v1');
    }

    private function suggestion(CalendarConnection $connection, string $taskId, DateTimeImmutable $start, int $seconds, ?string $groupKey): CalendarSuggestion
    {
        $suggestion = new CalendarSuggestion($connection, $this->managedUser(), hash('sha256', 'ref-' . ++$this->refs), null, $groupKey, $taskId, CalendarMatchKind::Key, $start, $start->modify('+' . $seconds . ' seconds'), $start->modify('+14 days'), new DateTimeImmutable());
        $this->entityManager()->persist($suggestion);

        return $suggestion;
    }

    private function day(int $daysAgo, int $hour): DateTimeImmutable
    {
        return (new DateTimeImmutable('today -' . $daysAgo . ' days'))->setTime($hour, 0);
    }

    private function token(ClientScope ...$scopes): string
    {
        return $this->tokenFor($this->managedUser(), ...$scopes);
    }

    /**
     * The test user as managed by the entity manager now (the browser resets the services after each request).
     */
    private function managedUser(): AppUser
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function tokenFor(UserInterface $user, ClientScope ...$scopes): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, array_values($scopes))['token'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rawApi(string $method, string $uri, array $payload, string $token): string
    {
        $this->api($method, $uri, $payload, $token);

        return (string) $this->client->getResponse()->getContent();
    }
}
