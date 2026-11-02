<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use function array_unique;
use function array_values;
use function max;

/**
 * Instance settings of the client API read on each request through {@see ClientApiSettingsProviderInterface}.
 */
final readonly class ClientApiSettings
{
    /** @var list<string> */
    public array $allowedOrigins;

    /** Rate limit window in seconds (at least 1). */
    public int $windowSeconds;

    /**
     * @param list<string> $allowedOrigins Exact CORS origins (`chrome-extension://<id>`, `moz-extension://<uuid>`, `extension://<id>`); no wildcard
     * @param int $perTokenLimit Requests per token per window (0 = off)
     * @param int $perIpLimit Requests per client IP per window on every API route (0 = off)
     * @param int $deviceIpLimit Requests per client IP per window on `device/code` and `device/token` (0 = off)
     * @param int $windowSeconds Rate limit window in seconds
     * @param bool $allowTauriWebviewOrigins Deprecated 1.x behaviour: also allow `tauri://localhost` and `http(s)://tauri.localhost`
     */
    public function __construct(
        array $allowedOrigins = [],
        public int $perTokenLimit = 60,
        public int $perIpLimit = 300,
        public int $deviceIpLimit = 30,
        int $windowSeconds = 60,
        public bool $allowTauriWebviewOrigins = true,
    ) {
        $this->allowedOrigins = array_values(array_unique($allowedOrigins));
        $this->windowSeconds  = max(1, $windowSeconds);
    }
}
