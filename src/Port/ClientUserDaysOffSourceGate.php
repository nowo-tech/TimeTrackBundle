<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserDaysOffProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientUserDaysOffSourceInterface} (1.23.0) where the bundle asks its internal shape
 * {@see ClientUserDaysOffProviderInterface} (the 1.x interface of that name was removed in 2.0). Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class ClientUserDaysOffSourceGate implements ClientUserDaysOffProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private ClientUserDaysOffSourceInterface $source)
    {
    }

    /**
     * The source's days off for the user's reference.
     */
    public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        return $this->source->daysOffOf(TimeTrackUserRefs::fromUser($user), $from, $to);
    }
}
