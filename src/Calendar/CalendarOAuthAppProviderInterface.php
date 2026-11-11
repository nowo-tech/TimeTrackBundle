<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Enum\CalendarProvider;

/**
 * Since 1.20 (Beacon request 2026-11-11): the host's runtime source of the calendar OAuth apps (client id, secret,
 * Microsoft tenant), configured with `calendar.oauth.app_provider` — for example a store encrypted in the host database
 * and edited in an admin page, instead of `.env`. Without it the static `calendar.oauth.*` configuration is used.
 *
 * The bundle asks on every use (the consent URL, the code exchange, each refresh and revoke, `isConfigured()`), at most
 * once per call, and never keeps the answer: a rotated secret applies to the next call, in FrankenPHP worker mode too.
 * Implementations should not memoise it across requests or messages either (REQ-RUNTIME-002).
 */
interface CalendarOAuthAppProviderInterface
{
    /**
     * The OAuth app of a provider, or null when none is configured (the provider is then hidden and its token calls
     * fail with `not_configured`). An app with an empty client id or secret counts as none. Throw nothing you would
     * not want in a log: an exception is treated as "not configured" and its message is dropped.
     *
     * @param string $provider `google` or `microsoft` ({@see CalendarProvider} values)
     */
    public function app(string $provider): ?CalendarOAuthApp;
}
