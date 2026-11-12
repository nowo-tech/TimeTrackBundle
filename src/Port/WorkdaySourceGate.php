<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see WorkdaySourceInterface} (1.23.0) where the bundle asks the deprecated
 * {@see WorkdayProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class WorkdaySourceGate implements WorkdayProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private WorkdaySourceInterface $source)
    {
    }

    /**
     * The source's working day for the user's reference.
     */
    public function today(UserInterface $user): ?WorkdayState
    {
        return $this->source->todayFor(TimeTrackUserRefs::fromUser($user));
    }

    /**
     * The source's result of the action for the user's reference.
     */
    public function act(UserInterface $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
    {
        return $this->source->actFor(TimeTrackUserRefs::fromUser($user), $action, $occurredAt, $idempotencyKey, $client);
    }
}
