<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

use DateTimeImmutable;

use const DATE_ATOM;

/**
 * A client-supplied start or end time and the time the server applied after its clamps (client API 1.10:
 * `startedAt` on `timer/start`, `endedAt` on `timer/stop`).
 */
final readonly class ClampedTime
{
    /** The value was later than now plus the clock-skew allowance. */
    public const REASON_FUTURE = 'future';

    /** The start was before the end of the user's last entry. */
    public const REASON_OVERLAP = 'overlap';

    /** The start was older than the backdate limit. */
    public const REASON_MAX_BACKDATE = 'max_backdate';

    /** The end was before the start of the running timer. */
    public const REASON_BEFORE_START = 'before_start';

    /** Backdating is off (`client_api.backdate.max_seconds: 0`): the server used now. */
    public const REASON_DISABLED = 'disabled';

    /**
     * @param DateTimeImmutable $requested The time the client sent (to the second)
     * @param DateTimeImmutable $applied The time the server stored
     * @param bool $clamped Whether the server moved the time for a reported reason
     * @param string|null $reason One of the REASON_* constants when clamped, else null
     */
    public function __construct(
        public DateTimeImmutable $requested,
        public DateTimeImmutable $applied,
        public bool $clamped = false,
        public ?string $reason = null,
    ) {
    }

    /**
     * Wire shape (`startedAtResult` / `endedAtResult`).
     *
     * @return array{requested: string, applied: string, clamped: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested->format(DATE_ATOM),
            'applied'   => $this->applied->format(DATE_ATOM),
            'clamped'   => $this->clamped,
            'reason'    => $this->reason,
        ];
    }
}
