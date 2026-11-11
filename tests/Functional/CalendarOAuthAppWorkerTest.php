<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Calendar\CalendarOAuthApp;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthClients;
use Nowo\TimeTrackBundle\Calendar\CalendarPkce;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarOAuthClient;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Tests\Stub\MutableCalendarOAuthAppProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\NoNetworkHttpResponses;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function count;
use function implode;
use function parse_str;
use function parse_url;
use function sprintf;
use function str_repeat;

use const PHP_URL_QUERY;

/**
 * Since 1.20 (REQ-RUNTIME-002, Beacon request 2026-11-11): FrankenPHP worker mode with the kernel kept alive and the
 * services resetter never run. One container serves 150 rounds that alternate the host's OAuth apps (Google and
 * Microsoft, the Microsoft tenant alternating too) — apps one, apps two, none — with a request through `http_kernel`
 * between them: every call uses the app stored at that moment (consent URL, token URL, code exchange, refresh), a
 * removed app hides the provider and fails its calls closed (Google still revokes by the token alone), and no secret
 * reaches the log. Every secret here is a fake marker built at run time.
 */
final class CalendarOAuthAppWorkerTest extends KernelTestCase
{
    private const REDIRECT = 'https://host.example.test/account/calendars/callback';

    private const STATE = 'state-0123456789abcdefghijklmnopqrstuv';

    /**
     * A production-like kernel (debug off) whose OAuth apps come from the mutable host store.
     *
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(['calendar' => ['oauth' => ['app_provider' => MutableCalendarOAuthAppProvider::class]]], false, false, false);
    }

    public function testEveryCallUsesTheAppStoredAtThatMoment(): void
    {
        $container = self::getContainer();
        $apps      = $container->get(MutableCalendarOAuthAppProvider::class);
        $clients   = $container->get('time_track_test.calendar_oauth_clients');
        $network   = $container->get(NoNetworkHttpResponses::class);
        $logger    = $container->get('logger');
        self::assertInstanceOf(MutableCalendarOAuthAppProvider::class, $apps);
        self::assertInstanceOf(CalendarOAuthClients::class, $clients);
        self::assertInstanceOf(NoNetworkHttpResponses::class, $network);
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $google    = $clients->client(CalendarProvider::Google);
        $microsoft = $clients->client(CalendarProvider::Microsoft);
        $pkce      = CalendarPkce::create();
        $secrets   = [];
        // Audit ML-I4: the Microsoft app alternates its tenant (its own, then none = the configured default `common`).
        $tenants = [0 => 'tenant-one.example', 1 => null];

        for ($round = 0; $round < 150; ++$round) {
            $this->request();
            $variant = $round % 3;
            if ($variant === 2) {
                $apps->apps = [];
                self::assertSame([], $clients->configuredProviders(), sprintf('round %d: no app, no provider', $round));
                $sent = count($network->bodies);
                foreach ([$google, $microsoft] as $client) {
                    foreach ([
                        static fn (): string => $client->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge),
                        static fn (): mixed => $client->exchangeCode('code-x', $pkce->verifier(), self::REDIRECT),
                        static fn (): mixed => $client->refresh('rt-x'),
                    ] as $call) {
                        try {
                            $call();
                            self::fail(sprintf('round %d: refused without an app', $round));
                        } catch (CalendarSyncException $e) {
                            self::assertSame(CalendarSyncException::NOT_CONFIGURED, $e->errorCode);
                        }
                    }
                }
                self::assertCount($sent, $network->bodies, sprintf('round %d: no token call without an app', $round));
                // Audit ML-L2: Google still revokes by the token alone (the test network answers 503: false).
                self::assertFalse($google->revoke('rt-x'));
                self::assertSame('token=rt-x', $network->bodies[count($network->bodies) - 1]);
                self::assertFalse($microsoft->revoke('rt-x'));

                continue;
            }
            $tenant     = $tenants[$variant];
            $apps->apps = [
                'google'    => new CalendarOAuthApp('worker-id-' . $variant, $this->secret('google', $variant)),
                'microsoft' => new CalendarOAuthApp('worker-ms-' . $variant, $this->secret('microsoft', $variant), $tenant),
            ];
            $secrets[] = $this->secret('google', $variant);
            $secrets[] = $this->secret('microsoft', $variant);
            self::assertSame([CalendarProvider::Google, CalendarProvider::Microsoft], $clients->configuredProviders(), sprintf('round %d', $round));
            $base = 'https://login.microsoftonline.com/' . ($tenant ?? 'common') . '/oauth2/v2.0/';
            self::assertStringStartsWith($base . 'authorize?', $microsoft->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge), sprintf('round %d: Microsoft consent URL with the current tenant', $round));
            foreach (['google' => [$google, 'worker-id-', GoogleCalendarOAuthClient::TOKEN_URL], 'microsoft' => [$microsoft, 'worker-ms-', $base . 'token']] as $name => [$client, $idPrefix, $tokenUrl]) {
                $id = $idPrefix . $variant;
                parse_str((string) parse_url($client->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge), PHP_URL_QUERY), $query);
                self::assertSame($id, $query['client_id'] ?? null, sprintf('round %d: %s consent URL with the current app', $round, $name));
                foreach ([
                    static fn (): mixed => $client->exchangeCode('code-x', $pkce->verifier(), self::REDIRECT),
                    static fn (): mixed => $client->refresh('rt-x'),
                ] as $call) {
                    try {
                        $call();
                        self::fail('the test network answers 503');
                    } catch (CalendarSyncException $e) {
                        self::assertSame(CalendarSyncException::PROVIDER_ERROR, $e->errorCode);
                    }
                    self::assertSame($tokenUrl, $network->urls[count($network->urls) - 1], sprintf('round %d: %s token URL', $round, $name));
                    parse_str($network->bodies[count($network->bodies) - 1], $form);
                    self::assertSame([$id, $this->secret($name, $variant)], [$form['client_id'] ?? null, $form['client_secret'] ?? null], sprintf('round %d: %s token call with the current app', $round, $name));
                }
            }
        }

        $log = implode("\n", [...$logger->messages(), ...$logger->contextValues()]);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $log, 'no client secret in the log');
        }
    }

    /**
     * A fake secret per provider and variant, built so no credential-like literal is committed.
     */
    private function secret(string $provider, int $variant): string
    {
        return 'REDACTED-TEST-' . $provider . '-' . $variant . '-' . str_repeat('w', 4);
    }

    /**
     * One request straight through the container's `http_kernel` (no services resetter), as a worker serves it.
     */
    private function request(): void
    {
        $kernel = self::getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create('/api/time-track/me', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
    }
}
