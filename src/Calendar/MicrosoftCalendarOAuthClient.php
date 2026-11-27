<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

use function array_key_exists;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Since 1.19 (Beacon spec 210 FR-002): Microsoft identity platform v2 OAuth for the Graph calendar readers —
 * authorization code with PKCE and the delegated scopes `offline_access` + `Calendars.ReadBasic` (default: no body,
 * task keys from titles only) or `Calendars.Read` (also the body, for task keys in the description), from
 * `calendar.microsoft_scope`. Graph has **no per-grant revoke** (`revokeSignInSessions` would sign the user out
 * everywhere): {@see self::revoke()} returns false; delete the stored tokens and tell the user to remove the app at
 * https://myapps.microsoft.com.
 *
 * Since 1.20 the app (client id, secret, tenant) may come from the host's {@see CalendarOAuthAppProviderInterface}
 * (`calendar.oauth.app_provider`), asked on every call and never kept; a null tenant there means
 * `calendar.oauth.microsoft.tenant`.
 *
 * Since 2.3 (Beacon spec 260 T001) the host may allow extra delegated Graph scopes (`calendar.microsoft_extra_scopes`,
 * e.g. `OnlineMeetings.ReadWrite`) and ask for them with **incremental consent**: {@see self::authorizationUrl()} takes
 * the extra scopes to request (a subset of the allow-list) on top of the calendar scope, so a person already connected
 * is asked again only for the new permission. The token answer's granted scopes are exposed by
 * {@see CalendarTokens::grantedScopes()}; the host stores them beside the tokens and passes them back to
 * {@see self::refresh()}, which then asks **only for the scopes actually granted** (an unconsented scope on a refresh is
 * refused by Entra ID with AADSTS65001 and would break the calendar sync). {@see self::accessTokenForScope()} gets an
 * access token for one granted extra scope (for the host's own Graph call). With an empty allow-list (the default) the
 * requests are the 2.2 ones.
 */
final readonly class MicrosoftCalendarOAuthClient implements CalendarOAuthClientInterface
{
    public const LOGIN_BASE = 'https://login.microsoftonline.com/';

    /** Since 1.20 (audit ML-I2): a tenant id, domain or alias; `.` and `..` are refused (path segments). */
    public const TENANT_PATTERN = '/^(?!\.{1,2}$)[A-Za-z0-9.-]{1,64}$/D';

    /** Graph resource prefix of the delegated scopes. */
    public const GRAPH_SCOPE_PREFIX = 'https://graph.microsoft.com/';

    /**
     * Since 2.3: an extra delegated Graph scope (`OnlineMeetings.ReadWrite`, `User.Read`, `Calendars.ReadWrite.Shared`):
     * dotted PascalCase words, no resource prefix, no OpenID scopes, no `.default`.
     */
    public const EXTRA_SCOPE_PATTERN = '/^[A-Z][A-Za-z]{0,63}(?:\.[A-Z][A-Za-z]{0,63}){1,3}$/D';

    /** Since 2.3: most extra scopes in `calendar.microsoft_extra_scopes`. */
    public const MAX_EXTRA_SCOPES = 10;

    /** @var list<string> */
    private array $extraScopes;

    private string $tenant;

    private CalendarOAuthAppSource $app;

    /**
     * @param CalendarTokenEndpoint $endpoint The token HTTP calls
     * @param string $clientId OAuth client id (`calendar.oauth.microsoft.client_id`; unused with an app provider)
     * @param string $clientSecret OAuth client secret (`calendar.oauth.microsoft.client_secret`; unused with an app provider)
     * @param string $tenant `common`, `organizations`, `consumers` or a tenant id / domain (anything else = `common`);
     *                       with an app provider, the default of an app without a tenant
     * @param string $scope `Calendars.ReadBasic` or `Calendars.Read` (`calendar.microsoft_scope`)
     * @param CalendarOAuthAppProviderInterface|null $appProvider Since 1.20: runtime app source (`calendar.oauth.app_provider`)
     * @param LoggerInterface|null $logger Since 1.20: warns when the app provider throws (no message, no secret)
     * @param list<string> $extraScopes Since 2.3: allow-list of extra delegated Graph scopes the host may request
     *                                  (`calendar.microsoft_extra_scopes`, {@see self::EXTRA_SCOPE_PATTERN}; default none)
     *
     * @throws InvalidArgumentException On an unknown calendar scope or a malformed, duplicated or calendar extra scope
     */
    public function __construct(
        private CalendarTokenEndpoint $endpoint,
        string $clientId = '',
        #[SensitiveParameter]
        string $clientSecret = '',
        string $tenant = 'common',
        private string $scope = MicrosoftCalendarEventSource::SCOPE_READ_BASIC,
        ?CalendarOAuthAppProviderInterface $appProvider = null,
        ?LoggerInterface $logger = null,
        array $extraScopes = [],
    ) {
        if (!in_array($scope, [MicrosoftCalendarEventSource::SCOPE_READ_BASIC, MicrosoftCalendarEventSource::SCOPE_READ], true)) {
            throw new InvalidArgumentException(sprintf('The Microsoft calendar scope must be "%s" or "%s".', MicrosoftCalendarEventSource::SCOPE_READ_BASIC, MicrosoftCalendarEventSource::SCOPE_READ));
        }
        $this->extraScopes = $this->checkedExtraScopes($extraScopes, $scope);
        $this->tenant      = $this->tenant($tenant);
        $this->app         = new CalendarOAuthAppSource(CalendarProvider::Microsoft, $clientId, $clientSecret, $this->tenant, $appProvider, $logger);
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
        return $this->app->isConfigured();
    }

    /**
     * `offline_access https://graph.microsoft.com/Calendars.ReadBasic` (or `Calendars.Read`): the calendar scopes, without
     * any extra scope (see {@see self::scopesWith()}).
     */
    public function scopes(): string
    {
        return $this->scopesWith([]);
    }

    /**
     * Since 2.3: the allow-listed extra scopes (`calendar.microsoft_extra_scopes`), in configuration order.
     *
     * @return list<string>
     */
    public function extraScopes(): array
    {
        return $this->extraScopes;
    }

    /**
     * Since 2.3: the calendar scopes plus the given extra scopes (each one allow-listed), as sent to Entra ID.
     *
     * @param list<string> $extraScopes
     *
     * @throws InvalidArgumentException When an extra scope is not in the allow-list
     */
    public function scopesWith(array $extraScopes): string
    {
        $scopes = 'offline_access ' . self::GRAPH_SCOPE_PREFIX . $this->scope;
        foreach ($this->requested($extraScopes) as $extra) {
            $scopes .= ' ' . self::GRAPH_SCOPE_PREFIX . $extra;
        }

        return $scopes;
    }

    /**
     * Consent URL (`select_account` prompt). Since 2.3, `$extraScopes` (a subset of {@see self::extraScopes()}) are asked
     * for on top of the calendar scope: incremental consent, the person sees only the permissions not granted yet. Pass
     * the same list to {@see self::exchangeCode()}.
     *
     * @param list<string> $extraScopes Since 2.3: extra scopes to request (default none)
     *
     * @throws InvalidArgumentException Also (since 2.3) when an extra scope is not in the allow-list
     */
    public function authorizationUrl(string $redirectUri, string $state, string $codeChallenge, array $extraScopes = []): string
    {
        $scopes = $this->scopesWith($extraScopes);
        $app    = $this->app->forCall();

        return $this->endpoint->authorizationUrl($this->url($app, 'authorize'), $redirectUri, $state, $codeChallenge, [
            'client_id'     => $app->clientId,
            'response_mode' => 'query',
            'scope'         => $scopes,
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Code exchange. Since 2.3 the tokens carry the granted scopes ({@see CalendarTokens::grantedScopes()}): store them.
     *
     * @param list<string> $extraScopes Since 2.3: the extra scopes passed to {@see self::authorizationUrl()}
     *
     * @throws InvalidArgumentException Also (since 2.3) when an extra scope is not in the allow-list
     */
    public function exchangeCode(#[SensitiveParameter] string $code, #[SensitiveParameter] string $codeVerifier, string $redirectUri, array $extraScopes = []): CalendarTokens
    {
        $scopes = $this->scopesWith($extraScopes);
        $app    = $this->app->forCall();

        return $this->endpoint->tokens($this->url($app, 'token'), [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $app->clientId,
            'client_secret' => $app->revealClientSecret(),
            'scope'         => $scopes,
        ]);
    }

    /**
     * Refresh (Microsoft rotates the refresh token: store the new one).
     *
     * Since 2.3, `$grantedScopes` (the stored {@see CalendarTokens::grantedScopes()}) keeps the allow-listed extra scopes
     * the person granted: only those are asked for, never an unconsented one. Without it (null, the 2.2 call) only the
     * calendar scopes are asked for. When Entra ID answers that consent is missing (AADSTS65001: the person removed a
     * permission), the refresh is retried once with the calendar scopes alone, so the calendar keeps syncing; the new
     * tokens' granted scopes then show the extra scope is gone.
     *
     * @param list<string>|null $grantedScopes Since 2.3: the scopes granted with the stored refresh token
     */
    public function refresh(#[SensitiveParameter] string $refreshToken, ?array $grantedScopes = null): CalendarTokens
    {
        $extras = $this->grantedExtraScopes($grantedScopes);
        $app    = $this->app->forCall();
        $form   = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $app->clientId,
            'client_secret' => $app->revealClientSecret(),
            'scope'         => $this->scopesWith($extras),
        ];
        if ($extras === []) {
            return $this->endpoint->tokens($this->url($app, 'token'), $form);
        }
        try {
            return $this->endpoint->tokens($this->url($app, 'token'), $form, true);
        } catch (CalendarSyncException $e) {
            if ($e->errorCode !== CalendarSyncException::CONSENT_REQUIRED) {
                throw $e;
            }
        }

        return $this->endpoint->tokens($this->url($app, 'token'), ['scope' => $this->scopes()] + $form);
    }

    /**
     * Since 2.3: an access token for one granted extra scope (e.g. `OnlineMeetings.ReadWrite` for the host's Graph
     * `POST /me/onlineMeetings` call), from the stored refresh token. Entra ID rotates the refresh token: store the new
     * one. Nothing is sent when the scope was not granted.
     *
     * @param string $extraScope An allow-listed extra scope
     * @param list<string>|null $grantedScopes The stored {@see CalendarTokens::grantedScopes()}
     *
     * @throws InvalidArgumentException When the scope is not in the allow-list
     * @throws CalendarSyncException `scope_not_granted` when the stored grant lacks the scope (ask for it with
     *                               {@see self::authorizationUrl()}); `consent_required` when Entra ID says the
     *                               person removed it (the calendar grant may still work: do not disconnect);
     *                               `token_expired` when the grant is gone; `not_configured` without an app
     */
    public function accessTokenForScope(#[SensitiveParameter] string $refreshToken, string $extraScope, ?array $grantedScopes): CalendarTokens
    {
        $canonical = $this->requested([$extraScope])[0];
        if (!in_array($canonical, $this->grantedExtraScopes($grantedScopes), true)) {
            throw new CalendarSyncException(CalendarSyncException::SCOPE_NOT_GRANTED);
        }
        $app = $this->app->forCall();

        return $this->endpoint->tokens($this->url($app, 'token'), [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $app->clientId,
            'client_secret' => $app->revealClientSecret(),
            'scope'         => 'offline_access ' . self::GRAPH_SCOPE_PREFIX . $canonical,
        ], true);
    }

    /**
     * Since 2.3: the allow-listed extra scopes found in granted scopes (with or without the Graph prefix, any case), in
     * allow-list order. Null (unknown) grants nothing.
     *
     * @param list<string>|null $grantedScopes
     *
     * @return list<string>
     */
    public function grantedExtraScopes(?array $grantedScopes): array
    {
        if ($grantedScopes === null || $this->extraScopes === []) {
            return [];
        }
        $granted = [];
        foreach ($grantedScopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = strtolower($scope);
            if (str_starts_with($scope, self::GRAPH_SCOPE_PREFIX)) {
                $scope = substr($scope, strlen(self::GRAPH_SCOPE_PREFIX));
            }
            $granted[$scope] = true;
        }
        $found = [];
        foreach ($this->extraScopes as $extra) {
            if (array_key_exists(strtolower($extra), $granted)) {
                $found[] = $extra;
            }
        }

        return $found;
    }

    /**
     * Since 2.3: whether an allow-listed extra scope is among the granted scopes (false for a scope outside the
     * allow-list or unknown grants): "connected, but no meeting permission yet".
     *
     * @param list<string>|null $grantedScopes
     */
    public function isGranted(?array $grantedScopes, string $extraScope): bool
    {
        $canonical = $this->canonical($extraScope);

        return $canonical !== null && in_array($canonical, $this->grantedExtraScopes($grantedScopes), true);
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

    /**
     * The tenant of the app (static: the configured one; provider: its own, else the configured one), checked.
     */
    private function url(CalendarOAuthApp $app, string $action): string
    {
        return self::LOGIN_BASE . $this->tenant($app->tenant ?? $this->tenant) . '/oauth2/v2.0/' . $action;
    }

    /**
     * The requested extra scopes, allow-listed, canonical spelling, without duplicates.
     *
     * @param list<string> $extraScopes
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private function requested(array $extraScopes): array
    {
        $requested = [];
        foreach ($extraScopes as $extra) {
            $canonical = is_string($extra) ? $this->canonical($extra) : null;
            if ($canonical === null) {
                throw new InvalidArgumentException('A requested Microsoft scope is not in calendar.microsoft_extra_scopes.');
            }
            $requested[$canonical] = $canonical;
        }

        return array_values($requested);
    }

    private function canonical(string $extraScope): ?string
    {
        foreach ($this->extraScopes as $allowed) {
            if (strtolower($allowed) === strtolower($extraScope)) {
                return $allowed;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $extraScopes
     *
     * @throws InvalidArgumentException
     *
     * @return list<string>
     */
    private function checkedExtraScopes(array $extraScopes, string $calendarScope): array
    {
        if (count($extraScopes) > self::MAX_EXTRA_SCOPES) {
            throw new InvalidArgumentException(sprintf('At most %d Microsoft extra scopes are allowed.', self::MAX_EXTRA_SCOPES));
        }
        $checked = [];
        foreach ($extraScopes as $extra) {
            if (!is_string($extra) || preg_match(self::EXTRA_SCOPE_PATTERN, $extra) !== 1) {
                throw new InvalidArgumentException('A Microsoft extra scope must be a Graph scope name such as "OnlineMeetings.ReadWrite" (no prefix, no OpenID scope).');
            }
            $key = strtolower($extra);
            if (array_key_exists($key, $checked) || $key === strtolower($calendarScope)) {
                throw new InvalidArgumentException(sprintf('The Microsoft extra scopes must be distinct and not the calendar scope: "%s".', $extra));
            }
            $checked[$key] = $extra;
        }

        return array_values($checked);
    }

    private function tenant(string $tenant): string
    {
        return preg_match(self::TENANT_PATTERN, $tenant) === 1 ? $tenant : 'common';
    }
}
