<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timer;

use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationRequiredException;
use Nowo\TimeTrackBundle\Integration\ServiceAccountPolicyInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Port\TaskAccessPolicyGate;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;

/**
 * Since 1.25.0 (Beacon spec 234 FR-023 / FR-024): timer start / stop / current for the host's own controllers, for a
 * principal that has no app password (an API token such as Beacon's `bt_` Task-API token, an MCP session). Public service.
 *
 * The host authenticates the principal and decides whether it may use the timer at all; it passes the account as a
 * {@see TimeTrackUserRef} carrying its security user object ({@see TimeTrackUserRefFactory}).
 * The bundle then checks the task through the configured {@see TaskAccessPolicyInterface} (`client_api.task_access_checker`)
 * **and** the task provider, and then the period lock, exactly as
 * the client API does for `POST timer/start`. The app-password client API is unchanged.
 *
 * - One running timer per account. {@see self::start()} on an account whose timer runs stops that timer first and
 *   reports its entry ({@see HostTimerStart::$stopped}); concurrent starts: the last call wins
 *   ({@see TimerService::startReplacing()}).
 * - Only the actor's own timer is read, started or stopped: an agent's call never stops a person's timer.
 * - Entries record the channel (`api`, `mcp`, …) and, through {@see ServiceAccountPolicyInterface}, whether the account
 *   is a service account. Their source is `web`.
 *
 * Since 2.1.0 (Beacon spec 256): {@see self::startAllocated()} starts on a task, a task and a subject, a subject alone or
 * nothing, with an optional activity type; {@see self::stop()} takes an optional allocation patch and is an interactive
 * stop (refused with `TimeAllocationRequiredException` under `entries.require_allocation` when the time has neither task
 * nor subject). `canLog` is asked for the actor on every subject it sets.
 *
 * Stateless (FrankenPHP worker mode, kernel reset false): nothing is kept between calls.
 */
final readonly class HostTimerApi
{
    private TaskAccessCheckerInterface $taskAccess;

    /**
     * Receives the timer use cases and the task access checker of the API (the same service the client API asks). Since
     * 2.0 (planned in 1.25, audit PA) it also takes a {@see TaskAccessPolicyInterface} directly (the only host interface
     * of the port); the container passes the bundle's own slot.
     */
    public function __construct(private TimerService $timers, TaskAccessPolicyInterface|TaskAccessCheckerInterface $taskAccess)
    {
        $this->taskAccess = $taskAccess instanceof TaskAccessPolicyInterface ? new TaskAccessPolicyGate($taskAccess) : $taskAccess;
    }

    /**
     * Starts the actor's timer on a task now, stopping the actor's running timer first if there is one.
     *
     * @param TimeTrackUserRef $actor The account, with its security user object
     * @param string $taskId Task to track (checked through the task provider)
     * @param string|null $note Note of the new timer
     * @param string $channel Channel label stored on the entry ({@see TimerChannel}: `api`, `mcp`, …)
     *
     * @throws LogicException When the reference carries no security user object
     * @throws InvalidArgumentException When the channel is not a valid label
     * @throws TaskNotTrackableException When the access checker refuses the task, or it is unknown or not trackable by
     *                                   the actor (nothing is stopped)
     * @throws PeriodLockedException When the current period is locked (nothing is stopped)
     * @throws PeriodSubmittedException When the current period is submitted (nothing is stopped)
     * @throws ActiveTimerConflictException When concurrent starts took the slot on every attempt
     */
    public function start(TimeTrackUserRef $actor, string $taskId, ?string $note = null, string $channel = 'api'): HostTimerStart
    {
        $user = $this->user($actor);
        // Audit PA-1: the host's access policy decides first, before the task provider is asked and before anything is
        // stopped; one answer for "refused" and "unknown", so the actor learns nothing about a task it may not see.
        if (!$this->taskAccess->canTrack($user, $taskId)) {
            throw TaskNotTrackableException::forTask($taskId);
        }

        $result = $this->timers->startReplacing($user, $taskId, ClientType::Web, $channel, $note === null ? [] : ['note' => $note]);

        return new HostTimerStart(
            TimerSnapshot::fromTimer($result['timer']),
            array_map(TimerEntrySnapshot::fromEntry(...), $result['stopped']),
        );
    }

    /**
     * Since 1.36.0 (Beacon spec 250, `entries.allow_without_task`): starts the actor's timer now without a task (e.g. time
     * spent on a CRM customer that the host links on its own side by the ids it gets back), stopping the actor's running
     * timer first if there is one, exactly like {@see self::start()}. The scope is checked before anything is stopped.
     *
     * @param TimeTrackUserRef $actor The account, with its security user object
     * @param string|null $description Free text of the timer (cleaned and cut like a note)
     * @param string|null $note Note of the new timer
     * @param string $channel Channel label stored on the entry ({@see TimerChannel}: `api`, `mcp`, …)
     * @param string|null $scopeKey Tenant scope (one of the actor's, compared exactly; null = the current one)
     *
     * @throws LogicException When the reference carries no security user object
     * @throws InvalidArgumentException When the channel is not a valid label
     * @throws TaskNotTrackableException When the option is off or the scope is not one of the actor's (nothing is stopped)
     * @throws PeriodLockedException When the current period is locked (nothing is stopped)
     * @throws PeriodSubmittedException When the current period is submitted (nothing is stopped)
     * @throws ActiveTimerConflictException When concurrent starts took the slot on every attempt
     */
    public function startWithoutTask(TimeTrackUserRef $actor, ?string $description = null, ?string $note = null, string $channel = 'api', ?string $scopeKey = null): HostTimerStart
    {
        $result = $this->timers->startReplacing($this->user($actor), null, ClientType::Web, $channel, $note === null ? [] : ['note' => $note], null, $description, $scopeKey);

        return new HostTimerStart(
            TimerSnapshot::fromTimer($result['timer']),
            array_map(TimerEntrySnapshot::fromEntry(...), $result['stopped']),
        );
    }

    /**
     * Since 2.1.0 (Beacon spec 256): starts the actor's timer now on an allocation (task alone, task and subject, subject
     * alone, or nothing with `entries.allow_without_task`), with an optional activity type, stopping the actor's running
     * timer first if there is one, exactly like {@see self::start()}. With a task the access policy decides first (as in
     * {@see self::start()}), then the subject is checked (`canLog` for the actor in the entry's scope). Nothing is stopped
     * when anything is refused.
     *
     * @param TimeTrackUserRef $actor The account, with its security user object
     * @param TimeAllocation $allocation What the time is logged on
     * @param string|null $note Note of the new timer
     * @param string $channel Channel label stored on the entry ({@see TimerChannel}: `api`, `mcp`, …)
     * @param string|null $scopeKey Tenant scope of a timer without a task (one of the actor's; null = the current one; ignored with a task)
     * @param string|null $description Free text of a timer without a task
     *
     * @throws LogicException When the reference carries no security user object
     * @throws InvalidArgumentException When the channel is not a valid label
     * @throws TaskNotTrackableException When the task is refused, unknown or not trackable, or (no task, no subject) the option is off or the scope is not the actor's
     * @throws TimeAllocationException When the subject or the activity type is refused
     * @throws PeriodLockedException When the current period is locked (nothing is stopped)
     * @throws PeriodSubmittedException When the current period is submitted (nothing is stopped)
     * @throws ActiveTimerConflictException When concurrent starts took the slot on every attempt
     */
    public function startAllocated(TimeTrackUserRef $actor, TimeAllocation $allocation, ?string $note = null, string $channel = 'api', ?string $scopeKey = null, ?string $description = null): HostTimerStart
    {
        $user = $this->user($actor);
        if ($allocation->taskId !== null && !$this->taskAccess->canTrack($user, $allocation->taskId)) {
            throw TaskNotTrackableException::forTask($allocation->taskId);
        }

        $result = $this->timers->startReplacing($user, $allocation, ClientType::Web, $channel, $note === null ? [] : ['note' => $note], null, $description, $scopeKey);

        return new HostTimerStart(
            TimerSnapshot::fromTimer($result['timer']),
            array_map(TimerEntrySnapshot::fromEntry(...), $result['stopped']),
        );
    }

    /**
     * Stops the actor's running timer now and returns its entry, or null when none runs.
     *
     * Since 2.1.0 an interactive stop: `$allocation` sets, changes or clears the subject (next to the task only for a type
     * that allows tasks) and the activity type of the entry; under `entries.require_allocation` a timer with neither task
     * nor subject (after the patch) is refused and keeps running.
     *
     * @param string|null $note Note added to the entry
     * @param TimeAllocationPatch|null $allocation Since 2.1.0: the allocation set at stop (null = keep the timer's)
     *
     * @throws LogicException When the reference carries no security user object
     * @throws TimeAllocationException When the patch's subject or activity type is refused (nothing is written)
     * @throws TimeAllocationRequiredException When the time misses a required allocation (the timer keeps running)
     * @throws TaskNotTrackableException When the patch changes the subject of a task timer whose task the access policy now refuses
     */
    public function stop(TimeTrackUserRef $actor, ?string $note = null, ?TimeAllocationPatch $allocation = null): ?TimerEntrySnapshot
    {
        $user = $this->user($actor);
        $this->assertTaskAccessForPatch($user, $allocation);
        $entry = $this->timers->stop($user, null, $note, $allocation, true);

        return $entry instanceof TimeEntry ? TimerEntrySnapshot::fromEntry($entry) : null;
    }

    /**
     * Since 2.1.0 (Beacon spec 256, audit ZBQ): sets, changes or clears the subject and the activity type of the actor's
     * running timer (task timer or not), with the checks of the client API's `timer/allocate`: on a task timer the access
     * policy decides first when the subject changes, then `canLog` for the actor in the timer's scope, `allowsTask()`,
     * the activity type, and the clearing rule. Under the owner's lock; last write wins; `TimerAllocatedEvent`.
     *
     * @throws LogicException When the reference carries no security user object
     * @throws TaskNotTrackableException When the subject of a task timer changes and the task is refused
     * @throws TimeAllocationException When the subject or activity type is refused, or the time would be left unallocated
     * @throws ActiveTimerConflictException When the running timer was replaced on every attempt
     *
     * @return TimerSnapshot|null The running timer as it is now, or null when none runs
     */
    public function allocate(TimeTrackUserRef $actor, TimeAllocationPatch $allocation): ?TimerSnapshot
    {
        $user = $this->user($actor);
        $this->assertTaskAccessForPatch($user, $allocation);
        $timer = $this->timers->allocateRunning($user, $allocation);

        return $timer instanceof ActiveTimer ? TimerSnapshot::fromTimer($timer) : null;
    }

    /**
     * The actor's running timer, or null.
     *
     * @throws LogicException When the reference carries no security user object
     */
    public function current(TimeTrackUserRef $actor): ?TimerSnapshot
    {
        $timer = $this->timers->getActive($this->user($actor));

        return $timer instanceof ActiveTimer ? TimerSnapshot::fromTimer($timer) : null;
    }

    /**
     * Audit ZBQ-L3 (design §13): a change of the subject of a running task timer asks the access policy about the task
     * again (one answer for "refused" and "unknown", as on start).
     *
     * @throws TaskNotTrackableException When the policy refuses the task
     */
    private function assertTaskAccessForPatch(UserInterface $user, ?TimeAllocationPatch $patch): void
    {
        $timer = $patch instanceof TimeAllocationPatch && $patch->changesSubject ? $this->timers->getActive($user) : null;
        if ($timer instanceof ActiveTimer && $timer->hasTask() && $patch->changesSubjectOf($timer->subject()) && !$this->taskAccess->canTrack($user, $timer->getTaskId())) {
            throw TaskNotTrackableException::forTask($timer->getTaskId());
        }
    }

    private function user(TimeTrackUserRef $actor): UserInterface
    {
        return TimeTrackUserRefs::securityUser($actor, 'HostTimerApi');
    }
}
