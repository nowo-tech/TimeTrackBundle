<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Source of the client API instance settings (allowed origins, rate limits), read on each request so a host can back
 * them with database settings (Beacon: InstanceSettings). Default: {@see ConfigClientApiSettingsProvider}.
 */
interface ClientApiSettingsProviderInterface
{
    /**
     * Current settings.
     */
    public function getSettings(): ClientApiSettings;
}
