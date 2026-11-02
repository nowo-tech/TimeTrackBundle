<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Optional host data of `GET me` (contract v0.2 §3/§4): a display name and the working hours the clients use to
 * suppress reminders outside them (right to disconnect; the API never pushes). Default:
 * {@see NullClientUserProfileProvider} (both null).
 */
interface ClientUserProfileProviderInterface
{
    /**
     * Name shown by the clients (null = none).
     */
    public function getDisplayName(UserInterface $user): ?string;

    /**
     * Working hours, e.g. `{timezone: "Europe/Madrid", days: {mon: [["09:00", "17:00"]], ...}}` (null = unknown).
     *
     * @return array<string, mixed>|null
     */
    public function getWorkingHours(UserInterface $user): ?array;
}
