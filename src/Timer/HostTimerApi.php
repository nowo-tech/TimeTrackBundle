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
use Nowo\TimeTrackBundle\Integration\ServiceAccountPolicyInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;

/**
 * Since 1.25.0 (Beacon spec 234 FR-023 / FR-024): timer start / stop / current for the host's own controllers, for a
 * principal that has no app password (an API token such as Beacon's `bt_` Task-API token, an MCP session). Public service.
 *
 * The host authenticates the principal and decides whether it may use the timer at all; it passes the account as a
 * {@see TimeTrackUserRef} carrying its security user object ({@see TimeTrackUserRefFactory}).
 * The bundle then checks the task through the configured {@see TaskAccessCheckerInterface} (`client_api.task_access_checker`,
 * or its framework-free twin `TaskAccessPolicyInterface`) **and** the task provider, and then the period lock, exactly as
 * the client API does for `POST timer/start`. The app-password client API is unchanged.
 *
 * - One running timer per account. {@see self::start()} on an account whose timer runs stops that timer first and
 *   reports its entry ({@see HostTimerStart::$stopped}); concurrent starts: the last call wins
 *   ({@see TimerService::startReplacing()}).
 * - Only the actor's own timer is read, started or stopped: an agent's call never stops a person's timer.
 * - Entries record the channel (`api`, `mcp`, …) and, through {@see ServiceAccountPolicyInterface}, whether the account
 *   is a service account. Their source is `web`.
 *
 * Stateless (FrankenPHP worker mode, kernel reset false): nothing is kept between calls.
 */
final readonly class HostTimerApi
{
    /**
     * Receives the timer use cases and the task access checker of the API (the same service the client API asks).
     */
    public function __construct(private TimerService $timers, private TaskAccessCheckerInterface $taskAccess)
    {
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
     * Stops the actor's running timer now and returns its entry, or null when none runs.
     *
     * @param string|null $note Note added to the entry
     *
     * @throws LogicException When the reference carries no security user object
     */
    public function stop(TimeTrackUserRef $actor, ?string $note = null): ?TimerEntrySnapshot
    {
        $entry = $this->timers->stop($this->user($actor), null, $note);

        return $entry instanceof TimeEntry ? TimerEntrySnapshot::fromEntry($entry) : null;
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

    private function user(TimeTrackUserRef $actor): UserInterface
    {
        return TimeTrackUserRefs::securityUser($actor, 'HostTimerApi');
    }
}
