<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.1.0 (Beacon spec 256, Q-TS-19, {@see TimeTrackEvents::TIMER_ALLOCATED}): the subject or activity type of a
 * running timer changed (`TimerService::allocateRunning()`, client API `timer/allocate`). Dispatched after the commit.
 * Ids and value objects only (REQ-ARCH-001 rule 4). Concurrent changes are serialised by the owner's lock; the last one
 * wins (a running timer has no version).
 */
final class TimerAllocatedEvent extends Event
{
    /**
     * @param string $userId Id of the timer owner
     * @param string $timerId Id of the running timer
     * @param string|null $taskId The timer's task (null = none)
     * @param string|null $scopeKey The timer's tenant scope of a timer without a task (null for a task timer or no scope)
     * @param TimeSubjectRef|null $previousSubject Subject before the change
     * @param string|null $previousActivityTypeKey Activity type before the change
     * @param TimeSubjectRef|null $subject Subject after the change
     * @param string|null $activityTypeKey Activity type after the change
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $timerId,
        public readonly ?string $taskId,
        public readonly ?string $scopeKey,
        public readonly ?TimeSubjectRef $previousSubject,
        public readonly ?string $previousActivityTypeKey,
        public readonly ?TimeSubjectRef $subject,
        public readonly ?string $activityTypeKey,
    ) {
    }
}
