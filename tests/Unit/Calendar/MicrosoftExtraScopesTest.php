<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Calendar\CalendarPkce;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Calendar\CalendarTokenEndpoint;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarOAuthClient;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarEventSource;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarOAuthClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_fill;
use function array_shift;
use function implode;
use function json_decode;
use function parse_str;
use function parse_url;
use function range;
use function str_repeat;

use const PHP_URL_QUERY;

/**
 * Since 2.3 (Beacon spec 260 T001): extra Microsoft Graph scopes with incremental consent; refreshes ask only for the
 * granted scopes. Every token and secret here is a fake marker.
 */
final class MicrosoftExtraScopesTest extends TestCase
{
    private const REDIRECT = 'https://host.example.test/account/calendars/callback';

    private const STATE = 'state-0123456789abcdefghijklmnopqrstuv';

    private const BASE = 'offline_access https://graph.microsoft.com/Calendars.ReadBasic';

    private const MEETINGS = 'OnlineMeetings.ReadWrite';

    private const CONSENT_REQUIRED = '{"error":"invalid_grant","error_codes":[65001],"suberror":"consent_required"}';

    /** @var list<array{url: string, form: array<mixed>}> */
    private array $requests = [];

    public function testWithoutExtraScopesTheRequestsAreThe22Ones(): void
    {
        $client = $this->client([new MockResponse('{"access_token":"at-REDACTED-TEST"}')], []);

        self::assertSame([], $client->extraScopes());
        self::assertSame(self::BASE, $client->scopes());
        self::assertSame(self::BASE, $client->scopesWith([]));
        self::assertSame([], $client->grantedExtraScopes(['OnlineMeetings.ReadWrite']), 'nothing allow-listed');
        $client->refresh('rt-REDACTED-TEST', ['OnlineMeetings.ReadWrite']);
        self::assertSame(self::BASE, $this->requests[0]['form']['scope'] ?? null);
    }

    public function testIncrementalConsentAndExchangeExposeTheGrantedScopes(): void
    {
        $pkce   = CalendarPkce::create();
        $client = $this->client([
            new MockResponse('{"access_token":"at-REDACTED-TEST","refresh_token":"rt-REDACTED-TEST","scope":"https://graph.microsoft.com/Calendars.ReadBasic https://graph.microsoft.com/OnlineMeetings.ReadWrite https://graph.microsoft.com/User.Read"}'),
        ]);

        self::assertSame([self::MEETINGS, 'User.Read'], $client->extraScopes());
        self::assertSame(self::BASE, $client->scopes(), 'the calendar scopes alone');
        $url = $client->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge, ['onlinemeetings.readwrite', self::MEETINGS]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(self::BASE . ' https://graph.microsoft.com/OnlineMeetings.ReadWrite', $query['scope'] ?? null, 'canonical spelling, once');
        self::assertSame(['select_account', 'S256'], [$query['prompt'] ?? null, $query['code_challenge_method'] ?? null], 'PKCE unchanged');

        $tokens = $client->exchangeCode('code-REDACTED-TEST', $pkce->verifier(), self::REDIRECT, [self::MEETINGS]);
        self::assertSame(self::BASE . ' https://graph.microsoft.com/OnlineMeetings.ReadWrite', $this->requests[0]['form']['scope'] ?? null);
        self::assertSame(['https://graph.microsoft.com/Calendars.ReadBasic', 'https://graph.microsoft.com/OnlineMeetings.ReadWrite', 'https://graph.microsoft.com/User.Read'], $tokens->grantedScopes());
        self::assertSame([self::MEETINGS, 'User.Read'], $client->grantedExtraScopes($tokens->grantedScopes()));
        self::assertTrue($client->isGranted($tokens->grantedScopes(), 'onlinemeetings.readwrite'));
        self::assertFalse($client->isGranted($tokens->grantedScopes(), 'Mail.Send'), 'outside the allow-list');
        self::assertFalse($client->isGranted(null, self::MEETINGS), 'unknown grants grant nothing');
        /* @phpstan-ignore argument.type */
        self::assertSame([self::MEETINGS], $client->grantedExtraScopes([7, 'ONLINEMEETINGS.READWRITE']), 'non-strings are ignored');
    }

    public function testAnUnlistedExtraScopeIsRefusedBeforeAnyCall(): void
    {
        $client = $this->client([]);
        /** @var list<string> $notAString */
        $notAString = json_decode('[42]', true);

        foreach ([
            static fn (): string => $client->authorizationUrl(self::REDIRECT, self::STATE, CalendarPkce::create()->challenge, ['Mail.Send']),
            static fn (): mixed => $client->exchangeCode('code-REDACTED-TEST', 'verifier', self::REDIRECT, $notAString),
            static fn (): mixed => $client->accessTokenForScope('rt-REDACTED-TEST', 'Mail.Send', [self::MEETINGS]),
        ] as $call) {
            try {
                $call();
                self::fail('an unlisted scope');
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString('Mail.Send', $e->getMessage());
            }
        }
        self::assertSame([], $this->requests);
    }

    public function testRefreshAsksOnlyForTheGrantedScopes(): void
    {
        $client = $this->client([
            new MockResponse('{"access_token":"at-REDACTED-TEST","refresh_token":"rt2-REDACTED-TEST"}'),
            new MockResponse('{"access_token":"at-REDACTED-TEST","scope":"Calendars.ReadBasic OnlineMeetings.ReadWrite"}'),
            new MockResponse('{"access_token":"at-REDACTED-TEST"}'),
        ]);

        $old = $client->refresh('rt-REDACTED-TEST');
        self::assertSame(self::BASE, $this->requests[0]['form']['scope'] ?? null, 'the 2.2 call');
        self::assertSame(['offline_access', 'https://graph.microsoft.com/Calendars.ReadBasic'], $old->grantedScopes(), 'no scope answered: the requested one (RFC 6749 §5.1)');

        $tokens = $client->refresh('rt-REDACTED-TEST', ['https://graph.microsoft.com/onlinemeetings.readwrite', 'Calendars.ReadBasic']);
        self::assertSame(self::BASE . ' https://graph.microsoft.com/OnlineMeetings.ReadWrite', $this->requests[1]['form']['scope'] ?? null);
        self::assertSame(['Calendars.ReadBasic', self::MEETINGS], $tokens->grantedScopes());

        $client->refresh('rt-REDACTED-TEST', ['Calendars.ReadBasic']);
        self::assertSame(self::BASE, $this->requests[2]['form']['scope'] ?? null, 'never an unconsented scope');
    }

    public function testARemovedExtraConsentFallsBackToTheCalendarScopes(): void
    {
        $client = $this->client([
            new MockResponse(self::CONSENT_REQUIRED, ['http_code' => 400]),
            new MockResponse('{"access_token":"at-REDACTED-TEST","refresh_token":"rt2-REDACTED-TEST","scope":"Calendars.ReadBasic"}'),
        ]);

        $tokens = $client->refresh('rt-REDACTED-TEST', [self::MEETINGS]);
        self::assertCount(2, $this->requests);
        self::assertSame(self::BASE, $this->requests[1]['form']['scope'] ?? null);
        self::assertSame('rt-REDACTED-TEST', $this->requests[1]['form']['refresh_token'] ?? null);
        self::assertSame(['Calendars.ReadBasic'], $tokens->grantedScopes());
        self::assertFalse($client->isGranted($tokens->grantedScopes(), self::MEETINGS));
    }

    public function testAnExpiredGrantWithExtraScopesIsNotRetried(): void
    {
        $client = $this->client([new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])]);

        try {
            $client->refresh('rt-REDACTED-TEST', [self::MEETINGS]);
            self::fail('expired');
        } catch (CalendarSyncException $e) {
            self::assertSame(CalendarSyncException::TOKEN_EXPIRED, $e->errorCode);
        }
        self::assertCount(1, $this->requests);
    }

    public function testAnAccessTokenForAGrantedExtraScope(): void
    {
        $client = $this->client([
            new MockResponse('{"access_token":"meeting-REDACTED-TEST","refresh_token":"rt2-REDACTED-TEST","scope":"OnlineMeetings.ReadWrite"}'),
            new MockResponse('{"error":"invalid_grant","suberror":"consent_required"}', ['http_code' => 400]),
            new MockResponse(self::CONSENT_REQUIRED, ['http_code' => 400]),
            new MockResponse('{"error":"invalid_grant","error_codes":"65001"}', ['http_code' => 400]),
        ]);

        $tokens = $client->accessTokenForScope('rt-REDACTED-TEST', 'onlinemeetings.readwrite', ['Calendars.ReadBasic', self::MEETINGS]);
        self::assertSame(['meeting-REDACTED-TEST', 'rt2-REDACTED-TEST', [self::MEETINGS]], [$tokens->accessToken(), $tokens->refreshToken(), $tokens->grantedScopes()]);
        self::assertSame(['refresh_token', 'offline_access https://graph.microsoft.com/OnlineMeetings.ReadWrite'], [$this->requests[0]['form']['grant_type'] ?? null, $this->requests[0]['form']['scope'] ?? null]);

        foreach ([CalendarSyncException::CONSENT_REQUIRED, CalendarSyncException::CONSENT_REQUIRED, CalendarSyncException::TOKEN_EXPIRED] as $code) {
            try {
                $client->accessTokenForScope('rt-REDACTED-TEST', self::MEETINGS, [self::MEETINGS]);
                self::fail($code);
            } catch (CalendarSyncException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testNothingIsSentForAnUngrantedExtraScope(): void
    {
        $client = $this->client([]);

        foreach ([null, ['Calendars.ReadBasic']] as $granted) {
            try {
                $client->accessTokenForScope('rt-REDACTED-TEST', self::MEETINGS, $granted);
                self::fail('not granted');
            } catch (CalendarSyncException $e) {
                self::assertSame(CalendarSyncException::SCOPE_NOT_GRANTED, $e->errorCode);
            }
        }
        self::assertSame([], $this->requests);
    }

    public function testConsentIsTokenExpiredOutsideTheExtraScopeCalls(): void
    {
        $endpoint = $this->endpoint([
            new MockResponse(self::CONSENT_REQUIRED, ['http_code' => 400]),
            new MockResponse('{"error":"interaction_required","suberror":"consent_required"}', ['http_code' => 400]),
        ]);

        foreach ([CalendarSyncException::TOKEN_EXPIRED, CalendarSyncException::PROVIDER_ERROR] as $i => $code) {
            try {
                $endpoint->tokens('https://login.microsoftonline.com/common/oauth2/v2.0/token', [], $i === 1);
                self::fail($code);
            } catch (CalendarSyncException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function invalidAllowLists(): iterable
    {
        yield 'prefixed' => [['https://graph.microsoft.com/OnlineMeetings.ReadWrite']];
        yield 'openid' => [['offline_access']];
        yield 'not a string' => [[42]];
        yield 'duplicated' => [[self::MEETINGS, 'ONLINEMEETINGS.READWRITE']];
        yield 'the calendar scope' => [['CALENDARS.READBASIC']];
        yield 'too many' => [array_fill(0, 11, 'A.B')];
    }

    /**
     * @param list<mixed> $extraScopes
     */
    #[DataProvider('invalidAllowLists')]
    public function testTheAllowListIsChecked(array $extraScopes): void
    {
        $this->expectException(InvalidArgumentException::class);

        /* @phpstan-ignore argument.type */
        new MicrosoftCalendarOAuthClient($this->endpoint([]), 'id', 'secret', 'common', MicrosoftCalendarEventSource::SCOPE_READ_BASIC, null, null, $extraScopes);
    }

    public function testGrantedScopesAreParsedDefensively(): void
    {
        self::assertNull(CalendarTokenEndpoint::grantedScopes(null, null));
        self::assertNull(CalendarTokenEndpoint::grantedScopes(['a'], 3));
        self::assertSame([], CalendarTokenEndpoint::grantedScopes('   ', 'ignored'));
        self::assertSame(['a', 'b'], CalendarTokenEndpoint::grantedScopes(null, "a\tb  a"));
        self::assertSame(['ok'], CalendarTokenEndpoint::grantedScopes('ok "quoted" back\\slash ' . str_repeat('x', 257), null), 'malformed tokens are dropped');
        $many = [];
        foreach (range(1, 70) as $i) {
            $many[] = 's' . $i;
        }
        self::assertCount(CalendarTokenEndpoint::MAX_GRANTED_SCOPES, CalendarTokenEndpoint::grantedScopes(implode(' ', $many), null) ?? []);
        self::assertNull((new GoogleCalendarOAuthClient($this->endpoint([new MockResponse('{"access_token":"at-REDACTED-TEST"}')]), 'id', 'secret'))->refresh('rt-REDACTED-TEST')->grantedScopes(), 'Google refresh: unknown');
    }

    /**
     * @param list<MockResponse> $responses
     * @param list<string> $extraScopes
     */
    private function client(array $responses, array $extraScopes = [self::MEETINGS, 'User.Read']): MicrosoftCalendarOAuthClient
    {
        return new MicrosoftCalendarOAuthClient($this->endpoint($responses), 'ms-client-id', 'secret-REDACTED-TEST', 'common', MicrosoftCalendarEventSource::SCOPE_READ_BASIC, null, null, $extraScopes);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function endpoint(array $responses): CalendarTokenEndpoint
    {
        $this->requests = [];
        $http           = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            parse_str((string) ($options['body'] ?? ''), $form);
            $this->requests[] = ['url' => $url, 'form' => $form];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new CalendarTokenEndpoint($http, new MockClock('2026-11-12 10:00:00 UTC'));
    }
}
