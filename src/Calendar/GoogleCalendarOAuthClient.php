<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Since 1.19 (Beacon spec 210 FR-002): Google OAuth 2.0 for the calendar readers — authorization code with PKCE,
 * offline access, the read-only scopes {@see self::SCOPES} (`calendar.events.readonly` for events and meetings,
 * `calendar.calendarlist.readonly` for {@see CalendarReaderInterface::calendars()}). Client id and secret come from
 * `calendar.oauth.google` (empty = not configured) or, since 1.20, from the host's
 * {@see CalendarOAuthAppProviderInterface} (`calendar.oauth.app_provider`), asked on every call and never kept.
 */
final readonly class GoogleCalendarOAuthClient implements CalendarOAuthClientInterface
{
    public const AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Read-only scopes asked for. */
    public const SCOPES = 'https://www.googleapis.com/auth/calendar.events.readonly https://www.googleapis.com/auth/calendar.calendarlist.readonly';

    private CalendarOAuthAppSource $app;

    /**
     * @param CalendarTokenEndpoint $endpoint The token HTTP calls
     * @param string $clientId OAuth client id (`calendar.oauth.google.client_id`; unused with an app provider)
     * @param string $clientSecret OAuth client secret (`calendar.oauth.google.client_secret`; unused with an app provider)
     * @param CalendarOAuthAppProviderInterface|null $appProvider Since 1.20: runtime app source (`calendar.oauth.app_provider`)
     * @param LoggerInterface|null $logger Since 1.20: warns when the app provider throws (no message, no secret)
     */
    public function __construct(
        private CalendarTokenEndpoint $endpoint,
        string $clientId = '',
        #[SensitiveParameter]
        string $clientSecret = '',
        ?CalendarOAuthAppProviderInterface $appProvider = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->app = new CalendarOAuthAppSource(CalendarProvider::Google, $clientId, $clientSecret, null, $appProvider, $logger);
    }

    /**
     * Google.
     */
    public function provider(): CalendarProvider
    {
        return CalendarProvider::Google;
    }

    /**
     * Client id and secret are set (since 1.20 read from the app provider when there is one).
     */
    public function isConfigured(): bool
    {
        return $this->app->isConfigured();
    }

    /**
     * {@see self::SCOPES}.
     */
    public function scopes(): string
    {
        return self::SCOPES;
    }

    /**
     * Consent URL: offline access, consent prompt (a refresh token every time), no incremental scopes.
     */
    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        return $this->endpoint->authorizationUrl(self::AUTHORIZATION_URL, $redirectUri, $state, $codeChallenge, [
            'client_id'              => $this->app->forCall()->clientId,
            'scope'                  => self::SCOPES,
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'false',
        ]);
    }

    /**
     * Code exchange.
     */
    public function exchangeCode(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier, string $redirectUri): CalendarTokens
    {
        $app = $this->app->forCall();

        return $this->endpoint->tokens(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $app->clientId,
            'client_secret' => $app->revealClientSecret(),
        ]);
    }

    /**
     * Refresh (Google sends no new refresh token: keep the stored one).
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): CalendarTokens
    {
        $app = $this->app->forCall();

        return $this->endpoint->tokens(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $app->clientId,
            'client_secret' => $app->revealClientSecret(),
        ]);
    }

    /**
     * Google revokes a grant by its access or refresh token.
     */
    public function supportsRevoke(): bool
    {
        return true;
    }

    /**
     * `POST /revoke` (best effort). Google revokes by the token alone (no client credentials), so it works whether or
     * not an app is configured: disconnecting or erasing a connection after the app was removed still ends the grant
     * (audit ML-L2).
     */
    public function revoke(#[SensitiveParameter] string $token): bool
    {
        return $token !== '' && $this->endpoint->revoke(self::REVOKE_URL, ['token' => $token]);
    }
}
