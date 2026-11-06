<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\CalendarMatchKind;

/**
 * Since 1.14: an event that matched a task, reduced to what a suggestion stores (contract §3.6): references, task,
 * kind and the clamped time range. No title.
 */
final readonly class CalendarMatch
{
    /**
     * @param string|null $ruleId The matching rule (rule matches only)
     * @param bool $grouped Whether the match is grouped per ISO week (series, or a rule with `group_matches`)
     */
    public function __construct(
        public string $eventRef,
        public ?string $seriesRef,
        public string $taskId,
        public CalendarMatchKind $kind,
        public ?string $ruleId,
        public bool $grouped,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
    }
}
