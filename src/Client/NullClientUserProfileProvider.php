<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default profile provider: no display name, no working hours.
 */
final readonly class NullClientUserProfileProvider implements ClientUserProfileProviderInterface
{
    /** Always null. */
    public function getDisplayName(UserInterface $user): ?string
    {
        return null;
    }

    /** Always null. */
    public function getWorkingHours(UserInterface $user): ?array
    {
        return null;
    }
}
