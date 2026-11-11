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
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_shift;
use function parse_str;
use function parse_url;
use function str_repeat;

use const DATE_ATOM;
use const PHP_URL_QUERY;

/**
 * Since 1.19 (Beacon request 2026-11-09, spec 210 FR-002): the optional OAuth helpers build PKCE consent URLs, call
 * constant token URLs and answer with codes only. Every token and secret here is a fake marker.
 */
final class CalendarOAuthClientTest extends TestCase
{
    private const REDIRECT = 'https://host.example.test/account/calendars/callback';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    public function testGoogleConsentUrlAndTokenCalls(): void
    {
        $pkce   = CalendarPkce::create();
        $client = new GoogleCalendarOAuthClient($this->endpoint([
            new MockResponse('{"access_token":"at-REDACTED-TEST","refresh_token":"rt-REDACTED-TEST","expires_in":3600}'),
            new MockResponse('{"access_token":"at2-REDACTED-TEST","expires_in":"120"}'),
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 400]),
        ]), 'google-client-id', 'secret-REDACTED-TEST');

        self::assertSame(CalendarProvider::Google, $client->provider());
        self::assertTrue($client->isConfigured());
        self::assertFalse((new GoogleCalendarOAuthClient($this->endpoint([])))->isConfigured());
        self::assertSame(GoogleCalendarOAuthClient::SCOPES, $client->scopes());

        $url = $client->authorizationUrl(self::REDIRECT, 'state-1', $pkce->challenge);
        self::assertStringStartsWith(GoogleCalendarOAuthClient::AUTHORIZATION_URL . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'client_id'              => 'google-client-id',
            'scope'                  => GoogleCalendarOAuthClient::SCOPES,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'false',
            'redirect_uri'           => self::REDIRECT,
            'response_type'          => 'code',
            'state'                  => 'state-1',
            'code_challenge'         => $pkce->challenge,
            'code_challenge_method'  => 'S256',
        ], $query);

        $tokens = $client->exchangeCode('code-REDACTED-TEST', $pkce->verifier, self::REDIRECT);
        self::assertSame(['at-REDACTED-TEST', 'rt-REDACTED-TEST', '2026-11-12T10:59:00+00:00'], [$tokens->accessToken, $tokens->refreshToken, $tokens->expiresAt?->format(DATE_ATOM)]);
        self::assertSame(['POST', GoogleCalendarOAuthClient::TOKEN_URL], [$this->requests[0]['method'], $this->requests[0]['url']]);
        parse_str($this->requests[0]['body'], $form);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'code-REDACTED-TEST', 'code_verifier' => $pkce->verifier, 'redirect_uri' => self::REDIRECT, 'client_id' => 'google-client-id', 'client_secret' => 'secret-REDACTED-TEST'], $form);

        $refreshed = $client->refresh('rt-REDACTED-TEST');
        self::assertSame(['at2-REDACTED-TEST', null, '2026-11-12T10:01:00+00:00'], [$refreshed->accessToken, $refreshed->refreshToken, $refreshed->expiresAt?->format(DATE_ATOM)]);
        parse_str($this->requests[1]['body'], $form);
        self::assertSame('refresh_token', $form['grant_type'] ?? null);

        self::assertTrue($client->supportsRevoke());
        self::assertTrue($client->revoke('rt-REDACTED-TEST'));
        self::assertSame(GoogleCalendarOAuthClient::REVOKE_URL, $this->requests[2]['url']);
        self::assertFalse($client->revoke('rt-REDACTED-TEST'), 'a refused revoke is reported, not thrown');
        self::assertFalse($client->revoke(''), 'nothing to revoke');
        self::assertCount(4, $this->requests);
    }

    public function testMicrosoftConsentUrlTokenCallsAndNoRevoke(): void
    {
        $pkce   = CalendarPkce::create();
        $client = new MicrosoftCalendarOAuthClient($this->endpoint([
            new MockResponse('{"access_token":"at-REDACTED-TEST","refresh_token":"rt-REDACTED-TEST"}'),
            new MockResponse('{"access_token":"at2-REDACTED-TEST","refresh_token":"rt2-REDACTED-TEST","expires_in":30}'),
        ]), 'ms-client-id', 'secret-REDACTED-TEST', 'contoso.example', MicrosoftCalendarEventSource::SCOPE_READ);

        self::assertSame(CalendarProvider::Microsoft, $client->provider());
        self::assertTrue($client->isConfigured());
        self::assertSame('offline_access https://graph.microsoft.com/Calendars.Read', $client->scopes());
        $url = $client->authorizationUrl('http://localhost:8000/callback', 'state-2', $pkce->challenge);
        self::assertStringStartsWith('https://login.microsoftonline.com/contoso.example/oauth2/v2.0/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(['ms-client-id', 'query', 'offline_access https://graph.microsoft.com/Calendars.Read', 'select_account', 'S256'], [$query['client_id'] ?? null, $query['response_mode'] ?? null, $query['scope'] ?? null, $query['prompt'] ?? null, $query['code_challenge_method'] ?? null]);

        $tokens = $client->exchangeCode('code-REDACTED-TEST', $pkce->verifier, self::REDIRECT);
        self::assertNull($tokens->expiresAt, 'no expires_in');
        self::assertSame('https://login.microsoftonline.com/contoso.example/oauth2/v2.0/token', $this->requests[0]['url']);
        parse_str($this->requests[0]['body'], $form);
        self::assertSame('offline_access https://graph.microsoft.com/Calendars.Read', $form['scope'] ?? null);
        $refreshed = $client->refresh('rt-REDACTED-TEST');
        self::assertSame(['rt2-REDACTED-TEST', '2026-11-12T10:00:00+00:00'], [$refreshed->refreshToken, $refreshed->expiresAt?->format(DATE_ATOM)], 'never in the past');

        self::assertFalse($client->supportsRevoke());
        self::assertFalse($client->revoke('rt-REDACTED-TEST'));
        self::assertCount(2, $this->requests, 'no revoke call');

        $default = new MicrosoftCalendarOAuthClient($this->endpoint([]), '', '', 'bad tenant/../');
        self::assertFalse($default->isConfigured());
        self::assertSame('offline_access https://graph.microsoft.com/Calendars.ReadBasic', $default->scopes());
        self::assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $default->authorizationUrl(self::REDIRECT, 's', $pkce->challenge));
    }

    public function testMicrosoftRefusesAnUnknownScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MicrosoftCalendarOAuthClient($this->endpoint([]), 'id', 'secret', 'common', 'Calendars.ReadWrite');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function badAuthorizationArguments(): iterable
    {
        $challenge = str_repeat('c', 43);
        yield 'http redirect' => ['http://host.example.test/callback', 's', $challenge];
        yield 'relative redirect' => ['/callback', 's', $challenge];
        yield 'user info' => ['https://user@host.example.test/callback', 's', $challenge];
        yield 'javascript' => ['javascript:alert(1)', 's', $challenge];
        yield 'empty state' => [self::REDIRECT, '', $challenge];
        yield 'short challenge' => [self::REDIRECT, 's', 'plain'];
    }

    #[DataProvider('badAuthorizationArguments')]
    public function testRefusesBadAuthorizationArguments(string $redirect, string $state, string $challenge): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GoogleCalendarOAuthClient($this->endpoint([]), 'id', 'secret'))->authorizationUrl($redirect, $state, $challenge);
    }

    public function testLoopbackRedirects(): void
    {
        self::assertTrue(CalendarTokenEndpoint::isRedirectUri('http://127.0.0.1:9000/cb'));
        self::assertTrue(CalendarTokenEndpoint::isRedirectUri('http://[::1]:9000/cb'));
        self::assertFalse(CalendarTokenEndpoint::isRedirectUri('ftp://host.example.test/cb'));
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function tokenFailures(): iterable
    {
        yield 'invalid grant' => [new MockResponse('{"error":"invalid_grant","error_description":"provider text"}', ['http_code' => 400]), CalendarSyncException::TOKEN_EXPIRED];
        yield 'other error' => [new MockResponse('{"error":"invalid_client"}', ['http_code' => 401]), CalendarSyncException::PROVIDER_ERROR];
        yield 'error without JSON' => [new MockResponse('<html>', ['http_code' => 500]), CalendarSyncException::PROVIDER_ERROR];
        yield 'success without JSON' => [new MockResponse('<html>'), CalendarSyncException::INVALID_RESPONSE];
        yield 'no access token' => [new MockResponse('{"token_type":"Bearer"}'), CalendarSyncException::INVALID_RESPONSE];
        yield 'JSON scalar' => [new MockResponse('"x"'), CalendarSyncException::INVALID_RESPONSE];
        yield 'declared too large' => [new MockResponse(str_repeat(' ', CalendarTokenEndpoint::MAX_RESPONSE_BYTES + 1), ['response_headers' => ['Content-Length' => (string) (CalendarTokenEndpoint::MAX_RESPONSE_BYTES + 1)]]), CalendarSyncException::TOO_LARGE];
        yield 'too large' => [new MockResponse('{"access_token":"' . str_repeat('a', 65_536) . '"}'), CalendarSyncException::TOO_LARGE];
        yield 'transport' => [new MockResponse('', ['error' => 'host unreachable']), CalendarSyncException::PROVIDER_ERROR];
    }

    #[DataProvider('tokenFailures')]
    public function testTokenFailuresAreCodes(MockResponse $response, string $code): void
    {
        $this->expectExceptionObject(new CalendarSyncException($code));

        (new GoogleCalendarOAuthClient($this->endpoint([$response]), 'id', 'secret'))->refresh('rt-REDACTED-TEST');
    }

    public function testWithoutAnHttpClientOrOnANonHttpsUrl(): void
    {
        $offline = new CalendarTokenEndpoint(null);
        try {
            $offline->tokens(GoogleCalendarOAuthClient::TOKEN_URL, []);
            self::fail('no HTTP client');
        } catch (CalendarSyncException $e) {
            self::assertSame(CalendarSyncException::NO_HTTP_CLIENT, $e->errorCode);
        }
        self::assertFalse($offline->revoke(GoogleCalendarOAuthClient::REVOKE_URL, ['token' => 't']));

        $this->expectExceptionObject(new CalendarSyncException(CalendarSyncException::PROVIDER_ERROR));
        $this->endpoint([])->tokens('http://host.example.test/token', []);
    }

    public function testATransportExceptionWhileReadingIsAProviderError(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('down');
        });

        $this->expectExceptionObject(new CalendarSyncException(CalendarSyncException::PROVIDER_ERROR));
        (new CalendarTokenEndpoint($http))->tokens(GoogleCalendarOAuthClient::TOKEN_URL, []);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function endpoint(array $responses): CalendarTokenEndpoint
    {
        $this->requests = [];
        $http           = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new CalendarTokenEndpoint($http, new MockClock('2026-11-12 10:00:00 UTC'));
    }
}
