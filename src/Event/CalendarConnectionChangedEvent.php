<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.14 ({@see TimeTrackEvents::CALENDAR_CONNECTION_CHANGED}, contract §9.1): a calendar connection changed state
 * (`from` null = created; `to` `revoked` = deleted). Ids, codes and the consent version only, for the host audit rows
 * (`time_track.calendar.*`).
 */
final class CalendarConnectionChangedEvent extends Event
{
    /**
     * Creates the event.
     */
    public function __construct(
        public readonly string $connectionId,
        public readonly string $userRef,
        public readonly string $scopeKey,
        public readonly string $provider,
        public readonly string $mode,
        public readonly ?string $from,
        public readonly string $to,
        public readonly string $consentVersion,
    ) {
    }
}
