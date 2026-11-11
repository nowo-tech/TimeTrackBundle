<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use SensitiveParameter;

use function in_array;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Since 1.19 (Beacon spec 210 FR-002): Microsoft identity platform v2 OAuth for the Graph calendar readers —
 * authorization code with PKCE and the delegated scopes `offline_access` + `Calendars.ReadBasic` (default: no body,
 * task keys from titles only) or `Calendars.Read` (also the body, for task keys in the description), from
 * `calendar.microsoft_scope`. Graph has **no per-grant revoke** (`revokeSignInSessions` would sign the user out
 * everywhere): {@see self::revoke()} returns false; delete the stored tokens and tell the user to remove the app at
 * https://myapps.microsoft.com.
 */
final readonly class MicrosoftCalendarOAuthClient implements CalendarOAuthClientInterface
{
    public const LOGIN_BASE = 'https://login.microsoftonline.com/';

    /** Graph resource prefix of the delegated scopes. */
    public const GRAPH_SCOPE_PREFIX = 'https://graph.microsoft.com/';

    private string $tenant;

    /**
     * @param CalendarTokenEndpoint $endpoint The token HTTP calls
     * @param string $clientId OAuth client id (`calendar.oauth.microsoft.client_id`)
     * @param string $clientSecret OAuth client secret (`calendar.oauth.microsoft.client_secret`)
     * @param string $tenant `common`, `organizations`, `consumers` or a tenant id / domain (anything else = `common`)
     * @param string $scope `Calendars.ReadBasic` or `Calendars.Read` (`calendar.microsoft_scope`)
     */
    public function __construct(
        private CalendarTokenEndpoint $endpoint,
        private string $clientId = '',
        #[SensitiveParameter]
        private string $clientSecret = '',
        string $tenant = 'common',
        private string $scope = MicrosoftCalendarEventSource::SCOPE_READ_BASIC,
    ) {
        if (!in_array($scope, [MicrosoftCalendarEventSource::SCOPE_READ_BASIC, MicrosoftCalendarEventSource::SCOPE_READ], true)) {
            throw new InvalidArgumentException(sprintf('The Microsoft calendar scope must be "%s" or "%s".', MicrosoftCalendarEventSource::SCOPE_READ_BASIC, MicrosoftCalendarEventSource::SCOPE_READ));
        }
        $this->tenant = preg_match('/^[A-Za-z0-9.-]{1,64}$/D', $tenant) === 1 ? $tenant : 'common';
    }

    /**
     * Microsoft 365.
     */
    public function provider(): CalendarProvider
    {
        return CalendarProvider::Microsoft;
    }

    /**
     * Client id and secret are set.
     */
    public function isConfigured(): bool
    {
        return trim($this->clientId) !== '' && trim($this->clientSecret) !== '';
    }

    /**
     * `offline_access https://graph.microsoft.com/Calendars.ReadBasic` (or `Calendars.Read`).
     */
    public function scopes(): string
    {
        return 'offline_access ' . self::GRAPH_SCOPE_PREFIX . $this->scope;
    }

    /**
     * Consent URL (`select_account` prompt).
     */
    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        return $this->endpoint->authorizationUrl($this->url('authorize'), $redirectUri, $state, $codeChallenge, [
            'client_id'     => $this->clientId,
            'response_mode' => 'query',
            'scope'         => $this->scopes(),
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Code exchange.
     */
    public function exchangeCode(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier, string $redirectUri): CalendarTokens
    {
        return $this->endpoint->tokens($this->url('token'), [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => $this->scopes(),
        ]);
    }

    /**
     * Refresh (Microsoft rotates the refresh token: store the new one).
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): CalendarTokens
    {
        return $this->endpoint->tokens($this->url('token'), [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => $this->scopes(),
        ]);
    }

    /**
     * No per-grant revoke in Graph.
     */
    public function supportsRevoke(): bool
    {
        return false;
    }

    /**
     * Nothing to call: always false.
     */
    public function revoke(#[SensitiveParameter] string $token): bool
    {
        return false;
    }

    private function url(string $action): string
    {
        return self::LOGIN_BASE . $this->tenant . '/oauth2/v2.0/' . $action;
    }
}
