<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

/**
 * Since 1.14: the `meetings` configuration (contract §22.9).
 */
final readonly class MeetingSettings
{
    /**
     * @param string|null $calendarsRoute Host route name of *Account → Integrations → Calendars* (`me.links.calendars`)
     */
    public function __construct(
        public bool $enabled = false,
        public int $maxRangeDays = 14,
        public int $maxPastDays = 1,
        public int $defaultLimit = 100,
        public int $refreshAfterSeconds = 300,
        public int $perHour = 60,
        public int $perMinute = 6,
        public ?string $calendarsRoute = null,
    ) {
    }
}
