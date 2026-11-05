<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

use function in_array;

/**
 * Since 1.14: the `calendar` configuration (contract §13).
 */
final readonly class CalendarSettings
{
    /**
     * @param list<string> $providers Enabled providers (`google`, `microsoft`)
     * @param list<string> $modes Enabled modes (`server`, `local`)
     */
    public function __construct(
        public bool $enabled = false,
        public array $providers = ['google', 'microsoft'],
        public array $modes = ['server'],
        public int $lookbackDays = 14,
        public int $suggestionTtlDays = 14,
        public int $syncIntervalMinutes = 60,
        public int $maxEventSeconds = 28_800,
        public int $maxRulesPerUser = 20,
    ) {
    }

    /**
     * Whether a provider is enabled.
     */
    public function allowsProvider(CalendarProvider $provider): bool
    {
        return in_array($provider->value, $this->providers, true);
    }

    /**
     * Whether a mode is enabled.
     */
    public function allowsMode(CalendarConnectionMode $mode): bool
    {
        return in_array($mode->value, $this->modes, true);
    }
}
