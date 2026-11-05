<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default profile provider: no display name, no working hours, no days off (1.12).
 */
final readonly class NullClientUserProfileProvider implements ClientUserProfileProviderInterface, ClientUserDaysOffProviderInterface
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

    /** Always null (days off unknown). */
    public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        return null;
    }
}
