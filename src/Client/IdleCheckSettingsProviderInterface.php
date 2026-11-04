<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Optional host service (`client_api.idle_check.provider`, since 1.10): per-user idle-check settings for `GET me`
 * (e.g. an organisation that disables the check or sets its own minutes). Return null to use the instance settings
 * ({@see ClientApiSettings}). Returned values are clamped into the allowed ranges; an exception falls back to the
 * instance settings.
 */
interface IdleCheckSettingsProviderInterface
{
    /**
     * Idle-check settings of the user, or null for the instance settings.
     */
    public function forUser(UserInterface $user): ?IdleCheckSettings;
}
