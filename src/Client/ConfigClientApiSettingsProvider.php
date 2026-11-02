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
     */
    public function __construct(
        array $allowedOrigins = [],
        int $perTokenLimit = 60,
        int $perIpLimit = 300,
        int $deviceIpLimit = 30,
        int $windowSeconds = 60,
        bool $allowTauriWebviewOrigins = true,
    ) {
        $this->settings = new ClientApiSettings($allowedOrigins, $perTokenLimit, $perIpLimit, $deviceIpLimit, $windowSeconds, $allowTauriWebviewOrigins);
    }

    /**
     * The configured settings.
     */
    public function getSettings(): ClientApiSettings
    {
        return $this->settings;
    }
}
