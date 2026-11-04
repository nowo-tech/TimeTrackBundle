<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use function max;
use function min;

/**
 * Idle-check settings the clients read from `GET me` (since 1.10): whether they ask the user about idle time, after
 * how many idle minutes, and how long the prompt waits for an answer. The server only ever receives the user's
 * decision (`timer/discard-idle`); no activity counter is sent or stored. Values are clamped into the allowed ranges.
 */
final readonly class IdleCheckSettings
{
    public const MIN_THRESHOLD_MINUTES = 5;

    public const MAX_THRESHOLD_MINUTES = 60;

    public const MIN_ANSWER_TIMEOUT_MINUTES = 1;

    public const MAX_ANSWER_TIMEOUT_MINUTES = 30;

    /** Idle minutes before the client asks (5–60). */
    public int $thresholdMinutes;

    /** Minutes the prompt waits for an answer (1–30). */
    public int $answerTimeoutMinutes;

    /**
     * Clamps the minutes into their ranges.
     */
    public function __construct(
        public bool $enabled = true,
        int $thresholdMinutes = 10,
        int $answerTimeoutMinutes = 5,
    ) {
        $this->thresholdMinutes     = max(self::MIN_THRESHOLD_MINUTES, min(self::MAX_THRESHOLD_MINUTES, $thresholdMinutes));
        $this->answerTimeoutMinutes = max(self::MIN_ANSWER_TIMEOUT_MINUTES, min(self::MAX_ANSWER_TIMEOUT_MINUTES, $answerTimeoutMinutes));
    }

    /**
     * Wire shape of `limits.idle_check` on `GET me`.
     *
     * @return array{threshold_minutes: int, answer_timeout_minutes: int}
     */
    public function limitsToArray(): array
    {
        return ['threshold_minutes' => $this->thresholdMinutes, 'answer_timeout_minutes' => $this->answerTimeoutMinutes];
    }
}
