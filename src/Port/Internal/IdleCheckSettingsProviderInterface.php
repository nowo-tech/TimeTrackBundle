<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Client\ClientApiSettings;
use Nowo\TimeTrackBundle\Client\IdleCheckSettings;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsPolicyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Optional host service (`client_api.idle_check.provider`, since 1.10): per-user idle-check settings for `GET me`
 * (e.g. an organisation that disables the check or sets its own minutes). Return null to use the instance settings
 * ({@see ClientApiSettings}). Returned values are clamped into the allowed ranges; an exception falls back to the
 * instance settings.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Client\IdleCheckSettingsProviderInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see IdleCheckSettingsPolicyInterface} (a gate adapts it).
 */
interface IdleCheckSettingsProviderInterface
{
    /**
     * Idle-check settings of the user, or null for the instance settings.
     */
    public function forUser(UserInterface $user): ?IdleCheckSettings;
}
