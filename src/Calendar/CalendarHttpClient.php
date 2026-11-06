<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use JsonException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function ctype_digit;
use function is_array;
use function json_decode;
use function str_starts_with;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.14: the one HTTP path of the calendar adapters (contract §6.2, §11.4): `GET` only, to URLs under a constant
 * base, Bearer token, 10 s timeout, no redirects, 2 MB per response, JSON only. A 429 waits once for `Retry-After` when
 * it is short (bounded inline wait, like TaskBoard), then fails with `rate_limited`. Errors are codes, never provider
 * messages; nothing is logged here.
 */
final readonly class CalendarHttpClient
{
    /** Largest accepted response body, in bytes. */
    public const MAX_RESPONSE_BYTES = 2_097_152;

    /** Request timeout, in seconds. */
    public const TIMEOUT_SECONDS = 10.0;

    /** Longest `Retry-After` waited inline once, in seconds. */
    public const MAX_INLINE_WAIT_SECONDS = 5;

    private ClockInterface $clock;

    /**
     * Receives the HTTP client (framework `http_client`; null without symfony/http-client) and the clock used for the
     * inline wait.
     */
    public function __construct(
        private ?HttpClientInterface $httpClient,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Decoded JSON object of a `GET`.
     *
     * @param string $baseUri Constant base the URL must start with (no other host is ever called)
     * @param array<string, string> $headers Extra headers
     *
     * @throws CalendarSyncException
     *
     * @return array<mixed>
     */
    public function getJson(string $baseUri, string $url, CalendarCredential $credential, array $headers = []): array
    {
        if (!$this->httpClient instanceof HttpClientInterface) {
            throw new CalendarSyncException(CalendarSyncException::NO_HTTP_CLIENT);
        }
        if (!str_starts_with($url, $baseUri)) {
            throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
        }

        for ($attempt = 0;; ++$attempt) {
            [$status, $retryAfter, $body] = $this->send($this->httpClient, $url, $credential, $headers);
            if ($status !== 429) {
                break;
            }
            if ($attempt > 0 || $retryAfter === null || $retryAfter > self::MAX_INLINE_WAIT_SECONDS) {
                throw new CalendarSyncException(CalendarSyncException::RATE_LIMITED);
            }
            $this->clock->sleep($retryAfter);
        }

        if ($status === 401) {
            throw new CalendarSyncException(CalendarSyncException::TOKEN_EXPIRED);
        }
        if ($status === 403) {
            throw new CalendarSyncException(CalendarSyncException::FORBIDDEN);
        }
        if ($status !== 200) {
            throw new CalendarSyncException(CalendarSyncException::PROVIDER_ERROR);
        }
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
        }
        if (!is_array($decoded)) {
            throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: int|null, 2: string} status, Retry-After seconds (429 only), body
     */
    private function send(HttpClientInterface $httpClient, string $url, CalendarCredential $credential, array $headers): array
    {
        try {
            $response = $httpClient->request('GET', $url, [
                'headers'       => ['Authorization' => 'Bearer ' . $credential->reveal(), 'Accept' => 'application/json'] + $headers,
                'timeout'       => self::TIMEOUT_SECONDS,
                'max_duration'  => self::TIMEOUT_SECONDS * 2,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status === 429) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? '';

                return [429, ctype_digit($retry) ? (int) $retry : null, ''];
            }
            if ($status !== 200) {
                return [$status, null, ''];
            }
            $length = $response->getHeaders(false)['content-length'][0] ?? null;
            if ($length !== null && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                $response->cancel();

                throw new CalendarSyncException(CalendarSyncException::TOO_LARGE);
            }
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            throw new CalendarSyncException(CalendarSyncException::PROVIDER_ERROR);
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new CalendarSyncException(CalendarSyncException::TOO_LARGE);
        }

        return [200, null, $body];
    }
}
