<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

/**
 * Since 1.14 (contract §6.2): read-only reader of the user's **primary** calendar (Q-O5). The bundle ships the Google
 * and Microsoft Graph adapters; constant base URIs, `GET` only, minimal fields, no redirects, bounded pages and size.
 */
interface CalendarEventSourceInterface
{
    /**
     * The provider this source reads.
     */
    public function provider(): CalendarProvider;

    /**
     * Events overlapping `[from, to)`, expanded into occurrences.
     *
     * @throws CalendarSyncException With a code (`token_expired`, `rate_limited`, `forbidden`, `provider_error`,
     *                               `invalid_response`, `too_large`), never a provider message
     *
     * @return iterable<CalendarEventView>
     */
    public function fetch(CalendarCredential $credential, DateTimeImmutable $from, DateTimeImmutable $to): iterable;
}
