<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use SensitiveParameter;

/**
 * Since 1.19 (Beacon request 2026-11-09, spec 210 FR-002): optional OAuth helpers for a host that connects calendars:
 * the authorization URL (authorization code with PKCE, read-only scopes), and the code, refresh and revoke calls. The
 * host keeps the state and the verifier (session), stores the tokens encrypted and builds the
 * {@see CalendarCredentialProviderInterface} on top; the bundle stores nothing.
 *
 * Token calls are `POST`s to constant URLs (10 s, no redirects, 64 KB at most). Errors are
 * {@see CalendarSyncException} codes (`token_expired` for `invalid_grant`), never provider messages or tokens.
 *
 * Since 1.20 the OAuth app may come from a host {@see CalendarOAuthAppProviderInterface} (`calendar.oauth.app_provider`),
 * read on every call: without an app there, {@see self::isConfigured()} is false, the consent URL, code exchange and
 * refresh throw `not_configured`. {@see self::revoke()} never needs the app (Google revokes by the token alone, audit
 * ML-L2; Microsoft never revokes).
 */
interface CalendarOAuthClientInterface
{
    /**
     * The provider.
     */
    public function provider(): CalendarProvider;

    /**
     * Whether the host configured an OAuth app (client id and secret) for it; hide the provider otherwise.
     */
    public function isConfigured(): bool;

    /**
     * The space-separated scopes asked for.
     */
    public function scopes(): string;

    /**
     * Where the browser goes to consent.
     *
     * @param string $redirectUri Absolute `https` callback URL without fragment (`http` only for `localhost` / `127.0.0.1`)
     * @param string $state Anti-CSRF value from {@see CalendarOAuthState::create()} (32-512 unreserved characters), kept in
     *                      the session, checked with {@see CalendarOAuthState::matches()} on the callback, used once
     * @param string $codeChallenge {@see CalendarPkce::$challenge}
     *
     * @throws InvalidArgumentException On a bad redirect URI, a short or malformed state, or a bad challenge
     * @throws CalendarSyncException `not_configured` (since 1.20) when the app provider has no app
     */
    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string;

    /**
     * Exchanges the authorization code (with the PKCE verifier).
     *
     * @throws CalendarSyncException `not_configured` (since 1.20) when the app provider has no app
     */
    public function exchangeCode(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier, string $redirectUri): CalendarTokens;

    /**
     * A new access token from a refresh token.
     *
     * @throws CalendarSyncException `token_expired` when the grant is gone (the user must connect again);
     *                               `not_configured` (since 1.20) when the app provider has no app: keep the tokens
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): CalendarTokens;

    /**
     * Whether {@see self::revoke()} can revoke the grant at the provider (Microsoft Graph cannot: the user removes the
     * app at https://myapps.microsoft.com).
     */
    public function supportsRevoke(): bool;

    /**
     * Revokes the grant at the provider, best effort: true when the provider confirmed, false otherwise (never
     * throws). Delete the stored tokens whatever the answer.
     */
    public function revoke(#[SensitiveParameter] string $token): bool;
}
