<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientUserDaysOffSourceInterface} (1.23.0) where the bundle asks the deprecated
 * {@see ClientUserDaysOffProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
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
