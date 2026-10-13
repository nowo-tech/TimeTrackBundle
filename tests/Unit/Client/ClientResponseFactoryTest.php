<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Client;

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

    public function testAnyExtensionOriginWhenOptedIn(): void
    {
        $factory = new ClientResponseFactory([], 'prod', allowAnyExtensionOrigin: true);
        foreach (['chrome-extension://abc', 'moz-extension://0f1e2d3c'] as $origin) {
            $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => $origin]);
            $response = $factory->json(['ok' => true], 200, $request);

            self::assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
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
        self::assertSame('Authorization, Content-Type', $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testJsonWithWildcardInDevOnly(): void
    {
        $factory  = new ClientResponseFactory(['*'], 'dev');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'https://example.com']);
        $response = $factory->json(null, 204, $request);

        self::assertSame('https://example.com', $response->headers->get('Access-Control-Allow-Origin'));
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

    public function testTauriOriginAllowed(): void
    {
        $factory  = new ClientResponseFactory([], 'prod');
        $request  = Request::create('/', 'GET', [], [], [], ['HTTP_Origin' => 'tauri://localhost']);
        $response = $factory->json(['ok' => true], 200, $request);

        self::assertSame('tauri://localhost', $response->headers->get('Access-Control-Allow-Origin'));
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
}
