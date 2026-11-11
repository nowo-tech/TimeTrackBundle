<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\Tests\Support\JsonSchemaSubset;
use Nowo\TimeTrackBundle\Tests\Support\MeetingFixtures;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use RuntimeException;

use function array_column;
use function array_filter;
use function array_values;
use function dirname;
use function file_get_contents;
use function implode;
use function json_decode;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * Since 1.14 (contract §22, AC-22..AC-30): `GET /api/time-track/me/meetings` — own meetings only, `meetings:read`
 * scope, validation answers, privacy headers on every answer, schema of the payload, rate limit, provider failures, no
 * personal data in logs, the `GET me` keys and "track the meeting" on `timer/switch` (Q-B22).
 */
final class MeetingsApiTest extends FunctionalTestCase
{
    private const URI = '/api/time-track/me/meetings';

    protected static function timeTrackConfig(): array
    {
        return [
            'meetings'   => ['enabled' => true, 'provider' => StubMeetingProvider::class, 'links_calendars_route' => 'nowo_time_track_index'],
            'client_api' => ['profile_provider' => MutableProfileProvider::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger()->records = [];
    }

    public function testOwnMeetingsWithTheDocumentedShape(): void
    {
        $other                                             = $this->createUser('other@example.com');
        $provider                                          = $this->provider();
        $now                                               = new DateTimeImmutable();
        $provider->meetings[(string) $this->user->getId()] = [
            MeetingFixtures::meeting('mtg_aaaaaaaaaaaaaaaa', $now->modify('+1 hour')->format(DATE_ATOM)),
            MeetingFixtures::meeting('mtg_bbbbbbbbbbbbbbbb', $now->modify('+2 hours')->format(DATE_ATOM), 30, ['private' => true]),
        ];
        $provider->meetings[(string) $other->getId()] = [MeetingFixtures::meeting('mtg_otherotherother1', $now->modify('+1 hour')->format(DATE_ATOM))];
        $provider->state                              = new MeetingSyncState(MeetingSyncStatus::Connected, new DateTimeImmutable('2026-11-05T06:58:41+01:00'), 120);

        $body = $this->api('GET', self::URI, null, $this->token());

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivacyHeaders();
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/schemas/meetings.v1.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        /* @var array<string, mixed> $schema */
        self::assertSame([], JsonSchemaSubset::validate($body, $schema), 'AC-26: the payload matches the published schema (additionalProperties false)');
        self::assertSame(['mtg_aaaaaaaaaaaaaaaa', 'mtg_bbbbbbbbbbbbbbbb'], array_column($body['meetings'] ?? [], 'id'), 'only the caller\'s meetings');
        self::assertSame(['connected', '2026-11-05T05:58:41Z', 120], [$body['sync']['status'] ?? null, $body['sync']['lastSyncAt'] ?? null, $body['sync']['refreshAfterSeconds'] ?? null]);
        self::assertSame('UTC', $body['tz'] ?? null, 'the working-hours zone');
        self::assertIsArray($body);
        self::assertArrayHasKey('title', $body['meetings'][1]);
        self::assertNull($body['meetings'][1]['title']);
        self::assertSame((string) $this->user->getId(), $provider->calls[0][0], 'the provider is asked for the authenticated user only');
        self::assertStringNotContainsString('@', (string) json_encode($body['meetings']), 'no e-mail anywhere');
        $this->assertNothingPersonalLogged();
        $access = array_values(array_filter($this->logger()->records, static fn (array $r): bool => ($r['context']['action'] ?? null) === 'client_me_meetings'));
        self::assertSame(['status' => 200, 'count' => 2, 'dropped' => 0], ['status' => $access[0]['context']['status'] ?? null, 'count' => $access[0]['context']['count'] ?? null, 'dropped' => $access[0]['context']['dropped'] ?? null]);
        self::assertArrayHasKey('duration_ms', $access[0]['context']);

        $profile = self::getContainer()->get(MutableProfileProvider::class);
        self::assertInstanceOf(MutableProfileProvider::class, $profile);
        $profile->hours = ['timezone' => 'Europe/Madrid', 'slots' => []];
        self::assertSame('Europe/Madrid', $this->api('GET', self::URI . '?limit=1', null, $this->token())['tz'] ?? null);
        self::assertTrue($this->api('GET', self::URI . '?limit=1', null, $this->token())['truncated'] ?? null);
    }

    public function testTokensAndScopes(): void
    {
        $body = $this->api('GET', self::URI, null, $this->login());
        self::assertResponseStatusCodeSame(403, 'old tokens do not get meetings:read');
        self::assertSame('insufficient_scope', $body['error'] ?? null);
        $this->assertPrivacyHeaders();
        $this->api('GET', self::URI);
        self::assertResponseStatusCodeSame(401);
        $this->assertPrivacyHeaders();
        $this->api('OPTIONS', self::URI, null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        $this->assertPrivacyHeaders();
    }

    public function testValidationNeverEchoesTheValue(): void
    {
        foreach ([
            '?from=ZZVALUEMARKER'                                                            => ['invalid_datetime', 'from'],
            '?to=2026-11-05'                                                                 => ['invalid_datetime', 'to'],
            '?from=' . (new DateTimeImmutable('-2 days'))->format('Y-m-d\TH:i:s\Z')          => ['invalid_range', 'from'],
            '?tz=Mars/ZZVALUEMARKER'                                                         => ['invalid_timezone', 'tz'],
            '?limit=ZZVALUEMARKER'                                                           => ['invalid_limit', 'limit'],
            '?userId=2'                                                                      => ['unknown_parameter', 'userId'],
            '?from=' . (new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i:s') . '+01:00' => ['invalid_datetime', 'from'],
        ] as $query => [$code, $field]) {
            $body = $this->api('GET', self::URI . $query, null, $this->token());
            self::assertResponseStatusCodeSame(400, $query);
            self::assertSame(['error' => $code, 'field' => $field], ['error' => $body['error'] ?? null, 'field' => $body['field'] ?? null]);
            self::assertStringNotContainsString('ZZVALUEMARKER', (string) $this->client->getResponse()->getContent());
            $this->assertPrivacyHeaders();
        }
    }

    public function testUnknownParametersAndRateLimit(): void
    {
        $token = $this->token();
        $this->api('GET', self::URI . '?user=2', null, $token);
        self::assertResponseStatusCodeSame(400);
        for ($i = 0; $i < 5; ++$i) {
            $this->api('GET', self::URI, null, $token);
            self::assertResponseStatusCodeSame(200);
        }
        $body = $this->api('GET', self::URI, null, $token);
        self::assertResponseStatusCodeSame(429, 'AC-30: the 7th call in a minute');
        $retry = $this->client->getResponse()->headers->get('Retry-After');
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $retry);
        self::assertSame((int) $retry, $body['retryAfter'] ?? null);
        $this->assertPrivacyHeaders();
        self::assertSame(200, $this->api('GET', self::URI, null, $this->token()) !== null ? $this->client->getResponse()->getStatusCode() : 0, 'per token');
    }

    public function testDisabledAndUnavailable(): void
    {
        $provider        = $this->provider();
        $token           = $this->token();
        $provider->state = new MeetingSyncState(MeetingSyncStatus::Disabled);
        $body            = $this->api('GET', self::URI, null, $token);
        self::assertResponseStatusCodeSame(404, 'the organization switched calendars off');
        self::assertSame('not_found', $body['error'] ?? null);
        $this->assertPrivacyHeaders();

        $provider->state    = new MeetingSyncState(MeetingSyncStatus::Connected);
        $provider->failWith = 'ZZ-PROVIDER-SECRET-MESSAGE';
        $body               = $this->api('GET', self::URI, null, $token);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('meetings_unavailable', $body['error'] ?? null);
        self::assertSame('60', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringNotContainsString('ZZ-PROVIDER-SECRET-MESSAGE', (string) $this->client->getResponse()->getContent());
        self::assertNotContains('ZZ-PROVIDER-SECRET-MESSAGE', $this->logger()->contextValues());
        self::assertContains(RuntimeException::class, $this->logger()->contextValues());

        $provider->failWith  = null;
        $provider->failState = true;
        $this->api('GET', self::URI, null, $token);
        self::assertResponseStatusCodeSame(503);

        $provider->failState = false;
        $provider->pageState = new MeetingSyncState(MeetingSyncStatus::Disabled);
        $this->api('GET', self::URI, null, $token);
        self::assertResponseStatusCodeSame(404, 'the provider answered disabled');
    }

    public function testMeKeysAndTrackTheMeeting(): void
    {
        $me = $this->api('GET', '/api/time-track/me', null, $this->login());
        self::assertTrue($me['features']['meetings'] ?? null);
        self::assertSame(['status' => 'connected', 'refreshAfterSeconds' => 300], $me['meetings'] ?? null);
        self::assertSame(['calendars' => null, 'workingTime' => null], $me['links'] ?? null, 'the test host is http: no link');

        $token  = $this->scoped(ClientScope::TimerWrite, ClientScope::TimerRead);
        $switch = $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-1', 'metadata' => ['origin' => 'meeting']], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['origin' => 'meeting'], $switch['timer']['metadata'] ?? null);
        $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-2', 'metadata' => ['origin' => 'meeting', 'x' => 1]], $token);
        self::assertResponseStatusCodeSame(400);
        $stopped = $this->api('POST', '/api/time-track/timer/stop', [], $token);
        self::assertSame('meeting', $stopped['entry']['metadata']['origin'] ?? null);
        $start = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-2', 'metadata' => ['origin' => 'meeting']], $token);
        self::assertResponseStatusCodeSame(201, 'the meeting origin is accepted without allow_metadata');
        self::assertSame(['origin' => 'meeting'], $start['timer']['metadata'] ?? null);
        $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(201);
    }

    private function assertPrivacyHeaders(): void
    {
        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-store, private', $headers->get('Cache-Control'), 'Symfony adds private to no-store');
        self::assertSame('no-cache', $headers->get('Pragma'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('Authorization', implode(', ', $headers->all('vary')));
        self::assertFalse($headers->has('ETag'));
        self::assertFalse($headers->has('Last-Modified'));
    }

    private function assertNothingPersonalLogged(): void
    {
        $haystack = (string) json_encode([$this->logger()->messages(), $this->logger()->contextValues()]);
        foreach ([MeetingFixtures::TITLE_MARKER, MeetingFixtures::NAME_MARKER, 'ZZPASSCODEMARKER', 'meet.google.com', 'mtg_'] as $marker) {
            self::assertStringNotContainsString($marker, $haystack);
        }
    }

    private function provider(): StubMeetingProvider
    {
        $provider = self::getContainer()->get(StubMeetingProvider::class);
        self::assertInstanceOf(StubMeetingProvider::class, $provider);

        return $provider;
    }

    private function logger(): RecordingLogger
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    private function token(): string
    {
        return $this->scoped(ClientScope::MeetingsRead);
    }

    private function scoped(ClientScope ...$scopes): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(AppUser::class, $user);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, array_values($scopes))['token'];
    }
}
