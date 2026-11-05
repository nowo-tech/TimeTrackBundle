<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Workday;

/**
 * Since 1.13 (R-GDPR §8): the user's working day in the host register.
 */
final readonly class WorkdayState
{
    public const STATUSES = ['idle', 'working', 'on_break', 'closed'];

    /**
     * @param string $status `idle` | `working` | `on_break` | `closed`
     * @param string $date Local date (Y-m-d)
     * @param list<array{type: string, start: string, end: string|null, source: string}> $segments `type` work|break, ISO 8601 times
     * @param list<string> $flags `missing_clock_out`, `late_entry`, `overtime`, …
     * @param string|null $reminder `clock_in` | `clock_out` | null (already filtered to working hours by the host)
     * @param list<string> $allowedActions Route segments of {@see WorkdayAction}
     */
    public function __construct(
        public string $status,
        public string $date,
        public string $timezone,
        public array $segments = [],
        public int $workedSeconds = 0,
        public int $breakSeconds = 0,
        public array $flags = [],
        public ?string $reminder = null,
        public array $allowedActions = [],
    ) {
    }

    /**
     * Client API shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status'         => $this->status,
            'date'           => $this->date,
            'timezone'       => $this->timezone,
            'segments'       => $this->segments,
            'workedSeconds'  => $this->workedSeconds,
            'breakSeconds'   => $this->breakSeconds,
            'flags'          => $this->flags,
            'reminder'       => $this->reminder,
            'allowedActions' => $this->allowedActions,
        ];
    }
}
