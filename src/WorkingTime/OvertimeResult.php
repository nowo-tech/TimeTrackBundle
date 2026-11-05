<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

/**
 * Since 1.13: overtime of a range: per closed day, per ISO week (on top of the daily part), informational day flags
 * (`rest_short`, `break_missing`, `long_day`) and the annual counter (art. 35.2 ET) with its cap flags.
 */
final readonly class OvertimeResult
{
    /**
     * @param array<string, int> $daily Local date => seconds
     * @param array<string, int> $weekly ISO week key => seconds beyond the daily part
     * @param array<string, list<string>> $flags Local date => flags
     * @param array{year: int, ratifiedSeconds: int, compensatedSeconds: int, countedSeconds: int, capSeconds: int, flags: list<string>} $annual
     */
    public function __construct(
        public string $policyId,
        public string $policyVersion,
        public array $daily,
        public array $weekly,
        public array $flags,
        public array $annual,
    ) {
    }
}
