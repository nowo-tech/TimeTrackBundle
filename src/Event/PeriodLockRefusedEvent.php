<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Enum\WriteKind;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.13 ({@see TimeTrackEvents::PERIOD_LOCK_REFUSED}): a bundle write path was refused by a period lock or a
 * submitted period. Ids only.
 */
final class PeriodLockRefusedEvent extends Event
{
    /**
     * @param string $userRef Opaque id of the user whose time was to be written
     * @param string $scopeKey Scope of the blocking timesheet
     * @param string $localDate Local date of the refused write (in the timesheet's zone)
     * @param string $errorCode `period_locked` or `period_submitted`
     */
    public function __construct(
        public readonly string $userRef,
        public readonly string $scopeKey,
        public readonly string $localDate,
        public readonly WriteKind $writeKind,
        public readonly string $timesheetId,
        public readonly string $errorCode,
    ) {
    }
}
