<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Calendar;

use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncException;
use Nowo\TimeTrackBundle\Tests\Stub\PsrOnlyClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function str_repeat;

/**
 * Since 1.14 (contract §6.2, §11.4, AC-15): the calendar HTTP path — GET only under a constant base, Bearer token, no
 * redirects, size cap, bounded inline wait on 429, error codes only.
 */
final class CalendarHttpClientTest extends TestCase
{
    private const BASE = 'https://calendar.example/v1/events';

    public function testDecodesAJsonObjectWithTheRequestShape(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('{"items":[]}');
        });

        $data = (new CalendarHttpClient($http))->getJson(self::BASE, self::BASE . '?a=1', new CalendarCredential('fake-token'), ['Prefer' => 'x']);

        self::assertSame(['items' => []], $data);
        self::assertSame('GET', $requests[0][0]);
        self::assertSame(self::BASE . '?a=1', $requests[0][1]);
        self::assertContains('Authorization: Bearer fake-token', $requests[0][2]['headers']);
        self::assertContains('Prefer: x', $requests[0][2]['headers']);
        self::assertSame(0, $requests[0][2]['max_redirects']);
        self::assertEqualsWithDelta(10.0, $requests[0][2]['timeout'], 0.001);
    }

    public function testWaitsOnceForAShortRetryAfter(): void
    {
        $clock = new MockClock('2026-11-05 10:00:00');
        $http  = new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '2']]),
            new MockResponse('{"ok":true}'),
        ]);

        self::assertSame(['ok' => true], (new CalendarHttpClient($http, $clock))->getJson(self::BASE, self::BASE, new CalendarCredential('t')));
        self::assertSame('2026-11-05 10:00:02', $clock->now()->format('Y-m-d H:i:s'));
    }

    /**
     * Since 1.19.1: a plain PSR-20 clock (no `sleep()`) is accepted; the short wait falls back to `usleep()`.
     */
    public function testAPlainPsrClockWaitsWithoutSleepingThroughTheClock(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
            new MockResponse('{"ok":true}'),
        ]);

        self::assertSame(['ok' => true], (new CalendarHttpClient($http, new PsrOnlyClock()))->getJson(self::BASE, self::BASE, new CalendarCredential('t')));
        self::assertSame(2, $http->getRequestsCount());
    }

    /**
     * @return iterable<string, array{list<MockResponse>, string}>
     */
    public static function failures(): iterable
    {
        yield '401' => [[new MockResponse('{"error":"x"}', ['http_code' => 401])], CalendarSyncException::TOKEN_EXPIRED];
        yield '403' => [[new MockResponse('', ['http_code' => 403])], CalendarSyncException::FORBIDDEN];
        yield '500' => [[new MockResponse('', ['http_code' => 500])], CalendarSyncException::PROVIDER_ERROR];
        yield '302 is not followed' => [[new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://evil.example/']])], CalendarSyncException::PROVIDER_ERROR];
        yield '429 long wait' => [[new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '60']])], CalendarSyncException::RATE_LIMITED];
        yield '429 without Retry-After' => [[new MockResponse('', ['http_code' => 429])], CalendarSyncException::RATE_LIMITED];
        yield '429 twice' => [[new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '1']]), new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '1']])], CalendarSyncException::RATE_LIMITED];
        yield 'declared too large' => [[new MockResponse(str_repeat(' ', CalendarHttpClient::MAX_RESPONSE_BYTES + 1), ['response_headers' => ['Content-Length' => (string) (CalendarHttpClient::MAX_RESPONSE_BYTES + 1)]])], CalendarSyncException::TOO_LARGE];
        yield 'streamed too large' => [[new MockResponse((static function (): iterable { yield str_repeat('a', CalendarHttpClient::MAX_RESPONSE_BYTES + 1); })())], CalendarSyncException::TOO_LARGE];
        yield 'not json' => [[new MockResponse('<html>')], CalendarSyncException::INVALID_RESPONSE];
        yield 'json scalar' => [[new MockResponse('"x"')], CalendarSyncException::INVALID_RESPONSE];
        yield 'transport error' => [[new MockResponse('', ['error' => 'connection reset'])], CalendarSyncException::PROVIDER_ERROR];
    }

    /**
     * @param list<MockResponse> $responses
     */
    #[DataProvider('failures')]
    public function testFailuresAreCodesOnly(array $responses, string $code): void
    {
        $client = new CalendarHttpClient(new MockHttpClient($responses), new MockClock());

        try {
            $client->getJson(self::BASE, self::BASE, new CalendarCredential('fake-token'));
            self::fail('expected a sync exception');
        } catch (CalendarSyncException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($code, $exception->getMessage());
            self::assertStringNotContainsString('fake-token', (string) $exception);
        }
    }

    public function testNeverCallsAnotherBase(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new LogicException('must not be called'));

        $this->expectExceptionObject(new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE));
        (new CalendarHttpClient($http))->getJson(self::BASE, 'https://evil.example/v1/events', new CalendarCredential('t'));
    }

    public function testWithoutAnHttpClient(): void
    {
        $this->expectExceptionObject(new CalendarSyncException(CalendarSyncException::NO_HTTP_CLIENT));
        (new CalendarHttpClient(null))->getJson(self::BASE, self::BASE, new CalendarCredential('t'));
    }
}
