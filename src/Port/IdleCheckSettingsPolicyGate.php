<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Client\IdleCheckSettings;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsPolicyInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see IdleCheckSettingsPolicyInterface} (1.23.0) where the bundle asks the deprecated
 * {@see IdleCheckSettingsProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class IdleCheckSettingsPolicyGate implements IdleCheckSettingsProviderInterface
{
    /**
     * Wraps the host policy.
     */
    public function __construct(private IdleCheckSettingsPolicyInterface $policy)
    {
    }

    /**
     * The policy's answer for the user's reference.
     */
    public function forUser(UserInterface $user): ?IdleCheckSettings
    {
        return $this->policy->settingsFor(TimeTrackUserRefs::fromUser($user));
    }
}
