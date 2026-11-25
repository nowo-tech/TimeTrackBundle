<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Settings from the bundle configuration (`client_api.allowed_origins`, `client_api.rate_limit.*`; the deprecated
 * `clients.cors_allowed_origins` is merged into the origins).
 */
final readonly class ConfigClientApiSettingsProvider implements ClientApiSettingsProviderInterface
{
    private ClientApiSettings $settings;

    /**
     * @param list<string> $allowedOrigins
     * @param bool $allowTauriWebviewOrigins @deprecated since 2.0, ignored (see {@see ClientApiSettings}); removed in 3.0
     */
    public function __construct(
        array $allowedOrigins = [],
        int $perTokenLimit = 60,
        int $perIpLimit = 300,
        int $deviceIpLimit = 30,
        int $windowSeconds = 60,
        bool $allowTauriWebviewOrigins = false,
        int $backdateMaxSeconds = 86_400,
        int $clockSkewSeconds = 120,
        bool $allowIdleReassign = true,
        bool $idleCheckEnabled = true,
        int $idleCheckThresholdMinutes = 10,
        int $idleCheckAnswerTimeoutMinutes = 5,
        bool $idleCheckUserCanDisable = false,
    ) {
        $this->settings = new ClientApiSettings(
            $allowedOrigins,
            $perTokenLimit,
            $perIpLimit,
            $deviceIpLimit,
            $windowSeconds,
            $allowTauriWebviewOrigins,
            $backdateMaxSeconds,
            $clockSkewSeconds,
            $allowIdleReassign,
            $idleCheckEnabled,
            $idleCheckThresholdMinutes,
            $idleCheckAnswerTimeoutMinutes,
            $idleCheckUserCanDisable,
        );
    }

    /**
     * The configured settings.
     */
    public function getSettings(): ClientApiSettings
    {
        return $this->settings;
    }
}
