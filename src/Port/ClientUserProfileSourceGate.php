<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientUserProfileSourceInterface} (1.23.0) where the bundle asks the deprecated
 * {@see ClientUserProfileProviderInterface}. Its optional extras (locale, days off, privacy) are reached through
 * {@see Ports} with {@see self::source()}. Exceptions propagate. Stateless.
 *
 * @internal
 */
final readonly class ClientUserProfileSourceGate implements ClientUserProfileProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private ClientUserProfileSourceInterface $source)
    {
    }

    /**
     * The host source (for its optional extras).
     */
    public function source(): ClientUserProfileSourceInterface
    {
        return $this->source;
    }

    /**
     * The source's display name for the user's reference.
     */
    public function getDisplayName(UserInterface $user): ?string
    {
        return $this->source->displayNameOf(TimeTrackUserRefs::fromUser($user));
    }

    /**
     * The source's working hours for the user's reference.
     */
    public function getWorkingHours(UserInterface $user): ?array
    {
        return $this->source->workingHoursOf(TimeTrackUserRefs::fromUser($user));
    }
}
