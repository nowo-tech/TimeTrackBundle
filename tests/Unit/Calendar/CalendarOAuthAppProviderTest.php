<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use Nowo\TimeTrackBundle\Calendar\CalendarOAuthApp;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthAppProviderInterface;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthClients;
use Nowo\TimeTrackBundle\Calendar\CalendarPkce;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Calendar\CalendarTokenEndpoint;
use Nowo\TimeTrackBundle\Calendar\CalendarTokens;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarOAuthClient;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarOAuthClient;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Tests\Stub\MutableCalendarOAuthAppProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function array_shift;
use function count;
use function is_string;
use function parse_str;
use function parse_url;
use function str_repeat;

use const PHP_URL_QUERY;

/**
 * Since 1.20 (Beacon request 2026-11-11): the OAuth helpers read their app from a host
 * {@see CalendarOAuthAppProviderInterface} on every call — a rotated secret is used on
 * the next call, a removed app hides the provider and fails its calls closed, and nothing secret reaches an exception.
 * Every secret here is a fake marker built at run time.
 */
final class CalendarOAuthAppProviderTest extends TestCase
{
    private const REDIRECT = 'https://host.example.test/account/calendars/callback';

    private const STATE = 'state-0123456789abcdefghijklmnopqrstuv';

    /** @var list<array{url: string, body: string}> */
    private array $requests = [];

    public function testARotatedAppIsUsedOnTheNextCall(): void
    {
        $apps   = new MutableCalendarOAuthAppProvider();
        $client = new GoogleCalendarOAuthClient($this->endpoint(), 'static-id', $this->secret('static'), $apps);
        $pkce   = CalendarPkce::create();

        $apps->apps['google'] = new CalendarOAuthApp('id-one', $this->secret('one'));
        self::assertSame('id-one', $this->clientIdOf($client->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge)));
        $client->exchangeCode('code-x', $pkce->verifier(), self::REDIRECT);
        self::assertSame(['id-one', $this->secret('one')], $this->lastCredentials());

        $apps->apps['google'] = new CalendarOAuthApp('id-two', $this->secret('two'));
        self::assertSame('id-two', $this->clientIdOf($client->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge)));
        $client->refresh('rt-x');
        self::assertSame(['id-two', $this->secret('two')], $this->lastCredentials(), 'the new secret on the very next call');

        $apps->apps['google'] = new CalendarOAuthApp('id-one', $this->secret('one'));
        $client->refresh('rt-x');
        self::assertSame(['id-one', $this->secret('one')], $this->lastCredentials(), 'and back again');
        self::assertSame(['google', 'google', 'google', 'google', 'google'], $apps->asked, 'one lookup per call, never cached');
        foreach ($this->requests as $request) {
            self::assertStringNotContainsString($this->secret('static'), $request['body'], 'the static values are ignored with a provider');
        }
    }

    public function testMicrosoftReadsItsAppAndTenantPerCall(): void
    {
        $apps   = new MutableCalendarOAuthAppProvider();
        $client = new MicrosoftCalendarOAuthClient($this->endpoint(), '', '', 'organizations', appProvider: $apps);
        $pkce   = CalendarPkce::create();

        $apps->apps['microsoft'] = new CalendarOAuthApp('ms-one', $this->secret('one'), 'contoso.example');
        self::assertTrue($client->isConfigured());
        self::assertStringStartsWith('https://login.microsoftonline.com/contoso.example/oauth2/v2.0/authorize?', $client->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge));
        $client->exchangeCode('code-x', $pkce->verifier(), self::REDIRECT);
        self::assertSame('https://login.microsoftonline.com/contoso.example/oauth2/v2.0/token', $this->requests[0]['url']);
        self::assertSame(['ms-one', $this->secret('one')], $this->lastCredentials());

        $apps->apps['microsoft'] = new CalendarOAuthApp('ms-two', $this->secret('two'));
        $client->refresh('rt-x');
        self::assertSame('https://login.microsoftonline.com/organizations/oauth2/v2.0/token', $this->requests[1]['url'], 'no tenant: the configured one');
        self::assertSame(['ms-two', $this->secret('two')], $this->lastCredentials());

        $apps->apps['microsoft'] = new CalendarOAuthApp('ms-three', $this->secret('three'), 'bad tenant/../');
        $client->refresh('rt-x');
        self::assertSame('https://login.microsoftonline.com/common/oauth2/v2.0/token', $this->requests[2]['url'], 'a malformed tenant: common');
        self::assertFalse($client->revoke('rt-x'), 'Graph never revokes');
    }

    public function testNoAppHidesTheProviderAndRejectsItsCalls(): void
    {
        $apps      = new MutableCalendarOAuthAppProvider();
        $google    = new GoogleCalendarOAuthClient($this->endpoint(), appProvider: $apps);
        $microsoft = new MicrosoftCalendarOAuthClient($this->endpoint(), appProvider: $apps);
        $clients   = new CalendarOAuthClients($google, $microsoft);
        $pkce      = CalendarPkce::create();

        foreach ([null, new CalendarOAuthApp('', $this->secret('x')), new CalendarOAuthApp('id', '  ')] as $app) {
            $apps->apps = ['google' => $app, 'microsoft' => $app];
            self::assertFalse($clients->isConfigured(CalendarProvider::Google));
            self::assertFalse($clients->isConfigured(CalendarProvider::Microsoft));
            self::assertSame([], $clients->configuredProviders());
            foreach ([$google, $microsoft] as $client) {
                $this->assertNotConfigured(static fn (): string => $client->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge));
                $this->assertNotConfigured(static fn (): CalendarTokens => $client->exchangeCode('code-x', $pkce->verifier(), self::REDIRECT));
                $this->assertNotConfigured(static fn (): CalendarTokens => $client->refresh('rt-x'));
                self::assertFalse($client->revoke('rt-x'));
            }
        }
        self::assertSame([], $this->requests, 'nothing is sent without an app');

        $apps->apps = ['microsoft' => new CalendarOAuthApp('ms', $this->secret('ms'))];
        self::assertSame([CalendarProvider::Microsoft], $clients->configuredProviders(), 'Google hidden, Microsoft offered');
        self::assertSame($google, $clients->client(CalendarProvider::Google));
        $apps->apps = ['google' => new CalendarOAuthApp('g', $this->secret('g')), 'microsoft' => new CalendarOAuthApp('ms', $this->secret('ms'))];
        self::assertSame([CalendarProvider::Google, CalendarProvider::Microsoft], $clients->configuredProviders());
        self::assertTrue($google->revoke('rt-x'), 'with an app Google revokes again');
        self::assertCount(1, $this->requests);
    }

    public function testAFailingStoreFailsClosedWithoutItsMessage(): void
    {
        $apps                 = new MutableCalendarOAuthAppProvider();
        $apps->apps['google'] = new CalendarOAuthApp('id', $this->secret('leak'));
        $apps->failing        = true;
        $client               = new GoogleCalendarOAuthClient($this->endpoint(), appProvider: $apps);

        self::assertFalse($client->isConfigured());
        $this->assertNotConfigured(static fn (): CalendarTokens => $client->refresh('rt-x'), $this->secret('leak'));
        self::assertFalse($client->revoke('rt-x'));
        self::assertSame([], $this->requests);
    }

    public function testTheSecretNeverReachesATokenError(): void
    {
        $apps                 = new MutableCalendarOAuthAppProvider();
        $apps->apps['google'] = new CalendarOAuthApp('id', $this->secret('err'));
        $client               = new GoogleCalendarOAuthClient($this->endpoint([new MockResponse('{"error":"invalid_client","error_description":"bad"}', ['http_code' => 401])]), appProvider: $apps);

        try {
            $client->refresh('rt-x');
            self::fail('refused');
        } catch (CalendarSyncException $e) {
            self::assertSame(CalendarSyncException::PROVIDER_ERROR, $e->errorCode);
            self::assertStringNotContainsString($this->secret('err'), $this->describe($e));
        }
    }

    public function testWithoutAProviderTheStaticValuesApplyAsBefore(): void
    {
        $google    = new GoogleCalendarOAuthClient($this->endpoint(), 'static-id', $this->secret('static'));
        $microsoft = new MicrosoftCalendarOAuthClient($this->endpoint(), '', '');
        $clients   = new CalendarOAuthClients($google, $microsoft);
        $pkce      = CalendarPkce::create();

        self::assertSame([CalendarProvider::Google], $clients->configuredProviders());
        self::assertSame('static-id', $this->clientIdOf($google->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge)));
        $google->refresh('rt-x');
        self::assertSame(['static-id', $this->secret('static')], $this->lastCredentials());
        // 1.19.1 behaviour kept: an unconfigured static app still builds a URL and sends its (empty) values.
        self::assertSame('', $this->clientIdOf($microsoft->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge)));
        $microsoft->refresh('rt-x');
        self::assertSame(['', ''], $this->lastCredentials());
        self::assertTrue((new GoogleCalendarOAuthClient($this->endpoint([new MockResponse('')])))->revoke('rt-x'));
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertNotConfigured(callable $call, ?string $secret = null): void
    {
        try {
            $call();
            self::fail('not_configured expected');
        } catch (CalendarSyncException $e) {
            self::assertSame(CalendarSyncException::NOT_CONFIGURED, $e->errorCode);
            self::assertSame('not_configured', $e->getMessage());
            self::assertNull($e->getPrevious(), 'the store exception is dropped');
            if ($secret !== null) {
                self::assertStringNotContainsString($secret, $this->describe($e));
            }
        }
    }

    private function describe(Throwable $e): string
    {
        return $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" . $e;
    }

    private function clientIdOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $id = $query['client_id'] ?? '?';

        return is_string($id) ? $id : '?';
    }

    /**
     * @return array{0: string, 1: string} client id and secret of the last token request
     */
    private function lastCredentials(): array
    {
        self::assertGreaterThan(0, count($this->requests));
        parse_str($this->requests[count($this->requests) - 1]['body'], $form);

        $id     = $form['client_id'] ?? '?';
        $secret = $form['client_secret'] ?? '?';

        return [is_string($id) ? $id : '?', is_string($secret) ? $secret : '?'];
    }

    /**
     * @param list<MockResponse>|null $responses Null: every call answers with a token
     */
    private function endpoint(?array $responses = null): CalendarTokenEndpoint
    {
        $this->requests = [];
        $http           = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => (string) ($options['body'] ?? '')];
            if ($responses === null) {
                return new MockResponse('{"access_token":"at-x","expires_in":3600}');
            }

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new CalendarTokenEndpoint($http, new MockClock('2026-11-12 10:00:00 UTC'));
    }

    /**
     * A fake secret, built so no credential-like literal is committed.
     */
    private function secret(string $label): string
    {
        return 'REDACTED-TEST-' . $label . '-' . str_repeat('z', 4);
    }
}
