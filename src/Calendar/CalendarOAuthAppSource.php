<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Exception;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use SensitiveParameter;

/**
 * Since 1.20: where an OAuth client reads its app. With a host {@see CalendarOAuthAppProviderInterface} the app is
 * asked for on every call and never kept (REQ-RUNTIME-002); a null answer, an incomplete app or an exception (its
 * message dropped) means "not configured" and the token calls fail closed. Without one, the static
 * `calendar.oauth.*` values apply exactly as in 1.19.
 *
 * @internal
 */
final readonly class CalendarOAuthAppSource
{
    private CalendarOAuthApp $static;

    /**
     * @param CalendarProvider $provider The client's provider
     * @param string $clientId Static client id (1.19 behaviour, used without a provider)
     * @param string $clientSecret Static client secret (1.19 behaviour, used without a provider)
     * @param string|null $tenant Static Microsoft tenant
     * @param CalendarOAuthAppProviderInterface|null $appProvider Host runtime provider (`calendar.oauth.app_provider`)
     */
    public function __construct(
        private CalendarProvider $provider,
        string $clientId = '',
        #[SensitiveParameter]
        string $clientSecret = '',
        ?string $tenant = null,
        private ?CalendarOAuthAppProviderInterface $appProvider = null,
    ) {
        $this->static = new CalendarOAuthApp($clientId, $clientSecret, $tenant);
    }

    /**
     * Whether an app with client id and secret is available now.
     */
    public function isConfigured(): bool
    {
        return $this->current()?->isComplete() ?? false;
    }

    /**
     * The app for one call. Static configuration: the configured values, as in 1.19 (even when empty). Host provider:
     * the current app, or `not_configured`.
     *
     * @throws CalendarSyncException `not_configured`
     */
    public function forCall(): CalendarOAuthApp
    {
        $app = $this->current();
        if (!$app instanceof CalendarOAuthApp || ($this->appProvider instanceof CalendarOAuthAppProviderInterface && !$app->isComplete())) {
            throw new CalendarSyncException(CalendarSyncException::NOT_CONFIGURED);
        }

        return $app;
    }

    /**
     * The app for one call, or null when the host provider has none.
     */
    public function tryForCall(): ?CalendarOAuthApp
    {
        try {
            return $this->forCall();
        } catch (CalendarSyncException) {
            return null;
        }
    }

    private function current(): ?CalendarOAuthApp
    {
        if (!$this->appProvider instanceof CalendarOAuthAppProviderInterface) {
            return $this->static;
        }
        try {
            return $this->appProvider->app($this->provider->value);
        } catch (Exception) {
            // Fail closed; the host message may carry store details, so it is dropped.
            return null;
        }
    }
}
