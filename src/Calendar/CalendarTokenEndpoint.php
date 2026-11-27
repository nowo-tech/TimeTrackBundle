<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_slice;
use function array_unique;
use function array_values;
use function ctype_digit;
use function http_build_query;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function max;
use function parse_url;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const PHP_QUERY_RFC3986;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;
use const PHP_URL_USER;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Since 1.19: the HTTP part shared by the OAuth clients: `POST` form requests to constant URLs, 10 s, no redirects,
 * 64 KB at most, JSON answers. Errors are {@see CalendarSyncException} codes; bodies, tokens and provider messages are
 * never logged or kept.
 *
 * @internal
 */
final readonly class CalendarTokenEndpoint
{
    /** Largest token answer, in bytes. */
    public const MAX_RESPONSE_BYTES = 65_536;

    /** Request timeout, in seconds. */
    public const TIMEOUT_SECONDS = 10.0;

    /** Seconds taken off `expires_in`, so a token is refreshed before it expires. */
    public const EXPIRY_MARGIN_SECONDS = 60;

    /** Since 2.3: most granted scopes kept from a token answer. */
    public const MAX_GRANTED_SCOPES = 64;

    /** Since 2.3: one granted scope (a scope token of RFC 6749 §3.3, at most 256 characters). */
    public const GRANTED_SCOPE_PATTERN = '/^[\x21\x23-\x5B\x5D-\x7E]{1,256}$/D';

    private ClockInterface $clock;

    /**
     * Receives the HTTP client (framework `http_client`; null without symfony/http-client) and the clock. Since 1.19.1
     * any PSR-20 clock is accepted (a host may alias `Psr\Clock\ClockInterface` to its own implementation).
     */
    public function __construct(
        private ?HttpClientInterface $httpClient,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Tokens from a token endpoint. Since 2.3 the granted scopes are read from the answer's `scope` (RFC 6749 §5.1:
     * when it is missing, the requested `scope` was granted; null when neither is known).
     *
     * @param array<string, string> $form
     * @param bool $consentAware Since 2.3: a missing consent (Entra ID `consent_required` / AADSTS65001) is
     *                           `consent_required` instead of `token_expired`
     *
     * @throws CalendarSyncException
     */
    public function tokens(string $url, #[SensitiveParameter] array $form, bool $consentAware = false): CalendarTokens
    {
        [$status, $body] = $this->post($url, $form);
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new CalendarSyncException(CalendarSyncException::TOO_LARGE);
        }
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CalendarSyncException($status === 200 ? CalendarSyncException::INVALID_RESPONSE : CalendarSyncException::PROVIDER_ERROR);
        }
        if ($status !== 200) {
            // `invalid_grant`: the code or the refresh token was revoked, expired or used — connect again.
            $invalidGrant = is_array($data) && ($data['error'] ?? null) === 'invalid_grant';
            if ($invalidGrant && $consentAware && $this->isConsentRequired($data)) {
                throw new CalendarSyncException(CalendarSyncException::CONSENT_REQUIRED);
            }

            throw new CalendarSyncException($invalidGrant ? CalendarSyncException::TOKEN_EXPIRED : CalendarSyncException::PROVIDER_ERROR);
        }
        $access = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!is_string($access) || $access === '') {
            throw new CalendarSyncException(CalendarSyncException::INVALID_RESPONSE);
        }
        $refresh   = $data['refresh_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        if (is_string($expiresIn) && ctype_digit($expiresIn)) {
            $expiresIn = (int) $expiresIn;
        }

        return new CalendarTokens(
            $access,
            is_string($refresh) && $refresh !== '' ? $refresh : null,
            is_int($expiresIn) ? DateTimeImmutable::createFromInterface($this->clock->now())->add(new DateInterval(sprintf('PT%dS', max(0, $expiresIn - self::EXPIRY_MARGIN_SECONDS)))) : null,
            self::grantedScopes($data['scope'] ?? null, $form['scope'] ?? null),
        );
    }

    /**
     * Since 2.3: the granted scopes of an answer (its `scope`, else the requested one), split on spaces, without
     * duplicates or malformed tokens, at most {@see self::MAX_GRANTED_SCOPES}; null when unknown.
     *
     * @return list<string>|null
     */
    public static function grantedScopes(mixed $answered, mixed $requested): ?array
    {
        $scope = is_string($answered) ? $answered : (is_string($requested) ? $requested : null);
        if ($scope === null) {
            return null;
        }
        $scopes = [];
        foreach (preg_split('/[ \t]+/', $scope, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match(self::GRANTED_SCOPE_PATTERN, $token) === 1) {
                $scopes[] = $token;
            }
        }

        return array_slice(array_values(array_unique($scopes)), 0, self::MAX_GRANTED_SCOPES);
    }

    /**
     * Best-effort revoke call: true on a 200 answer, false otherwise (never throws).
     *
     * @param array<string, string> $form
     */
    public function revoke(string $url, #[SensitiveParameter] array $form): bool
    {
        try {
            return $this->post($url, $form)[0] === 200;
        } catch (CalendarSyncException) {
            return false;
        }
    }

    /**
     * The authorization URL after checking the host's arguments.
     *
     * @param array<string, string> $query
     *
     * @throws InvalidArgumentException
     */
    public function authorizationUrl(string $base, string $redirectUri, string $state, string $codeChallenge, array $query): string
    {
        if (!self::isRedirectUri($redirectUri)) {
            throw new InvalidArgumentException('The OAuth redirect URI must be an absolute https URL (http only for localhost).');
        }
        if (!CalendarOAuthState::isValid($state) || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $codeChallenge) !== 1) {
            throw new InvalidArgumentException('The OAuth state must be 32-512 unreserved characters (CalendarOAuthState::create()) and the code challenge a PKCE S256 challenge.');
        }

        return $base . '?' . http_build_query($query + [
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * An absolute `https` URL without user information or fragment (RFC 6749 §3.1.2), or `http` on a loopback host.
     */
    public static function isRedirectUri(string $uri): bool
    {
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        $host   = strtolower((string) parse_url($uri, PHP_URL_HOST));
        if ($host === '' || parse_url($uri, PHP_URL_USER) !== null || str_contains($uri, '#')) {
            return false;
        }

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /**
     * Entra ID's missing-consent answer: `suberror: consent_required` or error code 65001.
     *
     * @param array<mixed> $data
     */
    private function isConsentRequired(array $data): bool
    {
        if (($data['suberror'] ?? null) === 'consent_required') {
            return true;
        }
        $codes = $data['error_codes'] ?? null;

        return is_array($codes) && in_array(65001, $codes, true);
    }

    /**
     * @param array<string, string> $form
     *
     * @throws CalendarSyncException
     *
     * @return array{0: int, 1: string} status and body
     */
    private function post(string $url, #[SensitiveParameter] array $form): array
    {
        if (!$this->httpClient instanceof HttpClientInterface) {
            throw new CalendarSyncException(CalendarSyncException::NO_HTTP_CLIENT);
        }
        if (!str_starts_with($url, 'https://')) {
            throw new CalendarSyncException(CalendarSyncException::PROVIDER_ERROR);
        }
        try {
            $response = $this->httpClient->request('POST', $url, [
                'body'          => $form,
                'headers'       => ['Accept' => 'application/json'],
                'timeout'       => self::TIMEOUT_SECONDS,
                'max_duration'  => self::TIMEOUT_SECONDS * 2,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $length = $response->getHeaders(false)['content-length'][0] ?? null;
            if ($length !== null && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                $response->cancel();

                throw new CalendarSyncException(CalendarSyncException::TOO_LARGE);
            }
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            throw new CalendarSyncException(CalendarSyncException::PROVIDER_ERROR);
        }

        return [$status, $body];
    }
}
