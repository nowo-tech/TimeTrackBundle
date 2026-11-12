<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see ClientUserProfileProviderInterface} (REQ-ARCH-001 rule 3), same
 * `client_api.profile_provider` option. The optional extras are {@see ClientUserLocaleSourceInterface},
 * {@see ClientUserDaysOffSourceInterface} and {@see ClientUserPrivacySourceInterface}, on the same service.
 */
interface ClientUserProfileSourceInterface
{
    /**
     * Name shown by the clients (null = none).
     */
    public function displayNameOf(TimeTrackUserRef $user): ?string;

    /**
     * Working hours (null = unknown), in either shape of {@see ClientUserProfileProviderInterface::getWorkingHours()}.
     *
     * @return array<string, mixed>|null
     */
    public function workingHoursOf(TimeTrackUserRef $user): ?array;
}
