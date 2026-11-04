<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Idle-check settings of a user for `GET me` (since 1.10): the host's {@see IdleCheckSettingsProviderInterface} when it
 * answers (values clamped into range by {@see IdleCheckSettings}), else the instance settings. A provider exception falls
 * back to the instance settings and is logged without user data.
 */
final readonly class IdleCheckSettingsResolver
{
    private LoggerInterface $logger;

    /**
     * @param ClientApiSettingsProviderInterface $settingsProvider Instance settings (fallback)
     * @param IdleCheckSettingsProviderInterface|null $provider Host per-user settings (`client_api.idle_check.provider`; null = none)
     * @param LoggerInterface|null $logger Logs provider failures (defaults to no logging)
     */
    public function __construct(
        private ClientApiSettingsProviderInterface $settingsProvider,
        private ?IdleCheckSettingsProviderInterface $provider = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Settings that apply to the user.
     */
    public function forUser(UserInterface $user): IdleCheckSettings
    {
        if ($this->provider instanceof IdleCheckSettingsProviderInterface) {
            try {
                $settings = $this->provider->forUser($user);
                if ($settings instanceof IdleCheckSettings) {
                    return new IdleCheckSettings($settings->enabled, $settings->thresholdMinutes, $settings->answerTimeoutMinutes, $settings->userCanDisable);
                }
            } catch (Throwable) {
                $this->logger->warning('TimeTrack idle-check settings provider failed; instance settings used.', ['bundle' => 'time_track', 'action' => 'idle_check_provider_failed']);
            }
        }

        return $this->settingsProvider->getSettings()->idleCheck;
    }
}
