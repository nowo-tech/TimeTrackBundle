<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see IdleCheckSettingsProviderInterface} (REQ-ARCH-001 rule 3), same
 * `client_api.idle_check.provider` option. Return null to use the instance settings; returned values are clamped into
 * the allowed ranges; an exception falls back to the instance settings.
 */
interface IdleCheckSettingsPolicyInterface
{
    /**
     * Idle-check settings of the user, or null for the instance settings.
     */
    public function settingsFor(TimeTrackUserRef $user): ?IdleCheckSettings;
}
