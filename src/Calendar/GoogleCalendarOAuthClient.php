<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use SensitiveParameter;

use function trim;

/**
 * Since 1.19 (Beacon spec 210 FR-002): Google OAuth 2.0 for the calendar readers — authorization code with PKCE,
 * offline access, the read-only scopes {@see self::SCOPES} (`calendar.events.readonly` for events and meetings,
 * `calendar.calendarlist.readonly` for {@see CalendarReaderInterface::calendars()}). Client id and secret come from
 * `calendar.oauth.google` (empty = not configured).
 */
final readonly class GoogleCalendarOAuthClient implements CalendarOAuthClientInterface
{
    public const AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Read-only scopes asked for. */
    public const SCOPES = 'https://www.googleapis.com/auth/calendar.events.readonly https://www.googleapis.com/auth/calendar.calendarlist.readonly';

    /**
     * @param CalendarTokenEndpoint $endpoint The token HTTP calls
     * @param string $clientId OAuth client id (`calendar.oauth.google.client_id`)
     * @param string $clientSecret OAuth client secret (`calendar.oauth.google.client_secret`)
     */
    public function __construct(
        private CalendarTokenEndpoint $endpoint,
        private string $clientId = '',
        #[SensitiveParameter]
        private string $clientSecret = '',
    ) {
    }

    /**
     * Google.
     */
    public function provider(): CalendarProvider
    {
        return CalendarProvider::Google;
    }

    /**
     * Client id and secret are set.
     */
    public function isConfigured(): bool
    {
        return trim($this->clientId) !== '' && trim($this->clientSecret) !== '';
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
            'client_id'              => $this->clientId,
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
        return $this->endpoint->tokens(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    /**
     * Refresh (Google sends no new refresh token: keep the stored one).
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): CalendarTokens
    {
        return $this->endpoint->tokens(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
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
     * `POST /revoke` (best effort).
     */
    public function revoke(#[SensitiveParameter] string $token): bool
    {
        return $token !== '' && $this->endpoint->revoke(self::REVOKE_URL, ['token' => $token]);
    }
}
