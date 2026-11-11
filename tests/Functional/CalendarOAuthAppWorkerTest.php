<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Calendar\CalendarOAuthApp;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthClients;
use Nowo\TimeTrackBundle\Calendar\CalendarPkce;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
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
 * services resetter never run. One container serves 150 rounds that alternate the host's OAuth app — app one, app two,
 * none — with a request through `http_kernel` between them: every call uses the app stored at that moment (consent
 * URL, code exchange, refresh), a removed app hides the provider and fails its calls closed, and no secret reaches the
 * log. Every secret here is a fake marker built at run time.
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
        $google  = $clients->client(CalendarProvider::Google);
        $pkce    = CalendarPkce::create();
        $secrets = [];

        for ($round = 0; $round < 150; ++$round) {
            $this->request();
            $variant = $round % 3;
            if ($variant === 2) {
                $apps->apps = [];
                self::assertSame([], $clients->configuredProviders(), sprintf('round %d: no app, no provider', $round));
                $sent = count($network->bodies);
                foreach ([
                    static fn (): string => $google->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge),
                    static fn (): mixed => $google->exchangeCode('code-x', $pkce->verifier(), self::REDIRECT),
                    static fn (): mixed => $google->refresh('rt-x'),
                ] as $call) {
                    try {
                        $call();
                        self::fail(sprintf('round %d: refused without an app', $round));
                    } catch (CalendarSyncException $e) {
                        self::assertSame(CalendarSyncException::NOT_CONFIGURED, $e->errorCode);
                    }
                }
                self::assertFalse($google->revoke('rt-x'));
                self::assertCount($sent, $network->bodies, sprintf('round %d: nothing sent without an app', $round));

                continue;
            }
            $id         = 'worker-id-' . $variant;
            $secret     = 'REDACTED-TEST-worker-' . $variant . '-' . str_repeat('w', 4);
            $secrets[]  = $secret;
            $apps->apps = ['google' => new CalendarOAuthApp($id, $secret)];
            self::assertSame([CalendarProvider::Google], $clients->configuredProviders(), sprintf('round %d', $round));
            parse_str((string) parse_url($google->authorizationUrl(self::REDIRECT, self::STATE, $pkce->challenge), PHP_URL_QUERY), $query);
            self::assertSame($id, $query['client_id'] ?? null, sprintf('round %d: consent URL with the current app', $round));
            foreach ([
                static fn (): mixed => $google->exchangeCode('code-x', $pkce->verifier(), self::REDIRECT),
                static fn (): mixed => $google->refresh('rt-x'),
            ] as $call) {
                try {
                    $call();
                    self::fail('the test network answers 503');
                } catch (CalendarSyncException $e) {
                    self::assertSame(CalendarSyncException::PROVIDER_ERROR, $e->errorCode);
                }
                parse_str($network->bodies[count($network->bodies) - 1], $form);
                self::assertSame([$id, $secret], [$form['client_id'] ?? null, $form['client_secret'] ?? null], sprintf('round %d: token call with the current app', $round));
            }
        }

        $log = implode("\n", [...$logger->messages(), ...$logger->contextValues()]);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $log, 'no client secret in the log');
        }
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
