<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeInterface;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Support\StorageTime;
use SensitiveParameter;

use function hash_hmac;

/**
 * Since 1.14 (contract §3.6): keyed, non-reversible references of calendar events. The key is derived per user from
 * the kernel secret, so a reference means nothing outside this installation and two users' references never match.
 * `event_ref = HMAC-SHA256(k_user, provider ‖ event id ‖ occurrence start UTC)`, `series_ref = HMAC-SHA256(k_user,
 * provider ‖ series id)`.
 */
final readonly class CalendarRefHasher
{
    /**
     * @param string $secret Kernel secret (`%kernel.secret%`)
     */
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * Reference of one occurrence (64 hex characters).
     */
    public function eventRef(string $userId, CalendarProvider $provider, string $eventId, DateTimeInterface $start): string
    {
        return hash_hmac('sha256', $provider->value . "\0" . $eventId . "\0" . StorageTime::utc($start), $this->userKey($userId));
    }

    /**
     * Reference of a recurring series (64 hex characters).
     */
    public function seriesRef(string $userId, CalendarProvider $provider, string $seriesId): string
    {
        return hash_hmac('sha256', $provider->value . "\0series\0" . $seriesId, $this->userKey($userId));
    }

    private function userKey(string $userId): string
    {
        return hash_hmac('sha256', 'nowo_time_track_calendar_ref' . "\0" . $userId, $this->secret, true);
    }
}
