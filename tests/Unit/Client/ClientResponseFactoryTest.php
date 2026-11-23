<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

use Nowo\TimeTrackBundle\Client\ClientApiSettings;
use Nowo\TimeTrackBundle\Client\ClientApiSettingsProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClientResponseFactoryTest extends TestCase
{
    public function testExtensionOriginsRejectedByDefault(): void
    {
        $factory = new ClientResponseFactory([], 'prod');
        foreach (['chrome-extension://abc', 'moz-extension://0f1e2d3c'] as $origin) {
            $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => $origin]);
            $response = $factory->json(['ok' => true], 200, $request);

            self::assertNull($response->headers->get('Access-Control-Allow-Origin'), $origin);
        }
    }

    public function testExplicitlyListedExtensionOriginAllowed(): void
    {
        $factory  = new ClientResponseFactory(['chrome-extension://abc'], 'prod');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'chrome-extension://abc']);
        $response = $factory->json(['ok' => true], 200, $request);

        self::assertSame('chrome-extension://abc', $response->headers->get('Access-Control-Allow-Origin'));

        $other = $factory->json(['ok' => true], 200, Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'chrome-extension://evil']));
        self::assertNull($other->headers->get('Access-Control-Allow-Origin'));
    }

    public function testTheRemovedAnyExtensionOptInIsIgnored(): void
    {
        // 2.0: the argument is accepted and ignored (clients.cors_allow_any_extension_origin is removed).
        $factory = new ClientResponseFactory([], 'prod', allowAnyExtensionOrigin: true);
        foreach (['chrome-extension://abc', 'moz-extension://0f1e2d3c'] as $origin) {
            $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => $origin]);
            $response = $factory->json(['ok' => true], 200, $request);

            self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        }

        $web = $factory->json(['ok' => true], 200, Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'https://evil.example.com']));
        self::assertNull($web->headers->get('Access-Control-Allow-Origin'));
    }

    public function testCorsNeverAllowsCredentials(): void
    {
        $factory  = new ClientResponseFactory(['https://app.example.com'], 'prod');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'https://app.example.com']);
        $response = $factory->json(['ok' => true], 200, $request);

        self::assertSame('https://app.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        self::assertSame('Authorization, Content-Type, Idempotency-Key', $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testAWildcardAllowsNothingInAnyEnvironment(): void
    {
        // 2.0: "*" is refused at build time; a factory built by hand with it matches no origin (not even "*").
        foreach (['dev', 'prod'] as $environment) {
            $factory = new ClientResponseFactory(['*'], $environment);
            foreach (['https://example.com', '*'] as $origin) {
                $response = $factory->json(null, 204, Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => $origin]));

                self::assertNull($response->headers->get('Access-Control-Allow-Origin'), $environment . ' ' . $origin);
            }
        }
    }

    public function testJsonRejectsWildcardInProd(): void
    {
        $factory  = new ClientResponseFactory(['*'], 'prod');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'https://example.com']);
        $response = $factory->json(null, 204, $request);

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testEmptyResponseWithoutRequest(): void
    {
        $factory  = new ClientResponseFactory(['https://app.example.com'], 'prod');
        $response = $factory->empty(401);

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testExplicitAllowedOrigin(): void
    {
        $factory  = new ClientResponseFactory(['https://app.example.com'], 'prod');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'https://app.example.com']);
        $response = $factory->empty(200, $request);

        self::assertSame('https://app.example.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testTauriWebviewOriginsAreNoLongerAllowed(): void
    {
        // 2.0: client_api.allow_tauri_webview_origins is removed; a settings provider asking for them is ignored.
        $settings = $this->createStub(ClientApiSettingsProviderInterface::class);
        $settings->method('getSettings')->willReturn(new ClientApiSettings(allowTauriWebviewOrigins: true));
        foreach ([new ClientResponseFactory([], 'prod'), new ClientResponseFactory([], 'prod', settingsProvider: $settings)] as $factory) {
            foreach (ClientResponseFactory::TAURI_WEBVIEW_ORIGINS as $origin) {
                $response = $factory->json(['ok' => true], 200, Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => $origin]));

                self::assertNull($response->headers->get('Access-Control-Allow-Origin'), $origin);
            }
        }
    }

    public function testDisallowedOriginIsRejected(): void
    {
        $factory  = new ClientResponseFactory(['https://allowed.example.com'], 'prod');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'https://evil.example.com']);
        $response = $factory->json(['ok' => true], 200, $request);

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testEmptyOriginHeaderIsIgnored(): void
    {
        $factory  = new ClientResponseFactory(['https://app.example.com'], 'prod');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => '']);
        $response = $factory->json(['ok' => true], 200, $request);

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testProviderOriginsMustBeExactInEveryEnvironment(): void
    {
        $provider = new class implements ClientApiSettingsProviderInterface {
            public function getSettings(): ClientApiSettings
            {
                return new ClientApiSettings(['*', 'chrome-extension://*', 'https://*.example.com', 'https://app.example.com/path', "https://app.example.com\n", 'moz-extension://0f1e2d3c'], allowTauriWebviewOrigins: false);
            }
        };

        foreach (['dev', 'prod'] as $env) {
            $factory = new ClientResponseFactory([], $env, settingsProvider: $provider);
            foreach (['https://evil.example.com', 'chrome-extension://abc', 'https://a.example.com', 'https://app.example.com'] as $origin) {
                $response = $factory->json(['ok' => true], 200, Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => $origin]));
                self::assertNull($response->headers->get('Access-Control-Allow-Origin'), $env . ' ' . $origin);
            }

            $listed = $factory->json(['ok' => true], 200, Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'moz-extension://0f1e2d3c']));
            self::assertSame('moz-extension://0f1e2d3c', $listed->headers->get('Access-Control-Allow-Origin'), $env);
        }
    }
}
