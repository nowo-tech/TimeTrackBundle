<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Dto\BackdateLimits;

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

    /** Limits of client-supplied start / end times (since 1.10, clamped into range). */
    public BackdateLimits $backdate;

    /** Instance idle-check settings (since 1.10, clamped into range). */
    public IdleCheckSettings $idleCheck;

    /**
     * @param list<string> $allowedOrigins Exact CORS origins (`chrome-extension://<id>`, `moz-extension://<uuid>`, `extension://<id>`); no wildcard
     * @param int $perTokenLimit Requests per token per window (0 = off)
     * @param int $perIpLimit Requests per client IP per window on every API route (0 = off)
     * @param int $deviceIpLimit Requests per client IP per window on `device/code` and `device/token` (0 = off)
     * @param int $windowSeconds Rate limit window in seconds
     * @param bool $allowTauriWebviewOrigins Deprecated 1.x behaviour: also allow `tauri://localhost` and `http(s)://tauri.localhost`
     * @param int $backdateMaxSeconds How far back `startedAt` may go, 0..86400 (0 = off; since 1.10)
     * @param int $clockSkewSeconds Future tolerance of `startedAt` / `endedAt` read as now, 0..900 (since 1.10)
     * @param bool $allowIdleReassign Accept `timer/discard-idle` action `reassign` (since 1.10)
     * @param bool $idleCheckEnabled Clients ask the user about idle time (since 1.10)
     * @param int $idleCheckThresholdMinutes Idle minutes before the client asks, 5..60 (since 1.10)
     * @param int $idleCheckAnswerTimeoutMinutes Minutes the idle prompt waits for an answer, 1..30 (since 1.10)
     * @param bool $idleCheckUserCanDisable Whether users may turn the idle check off in the client (since 1.10.1)
     */
    public function __construct(
        array $allowedOrigins = [],
        public int $perTokenLimit = 60,
        public int $perIpLimit = 300,
        public int $deviceIpLimit = 30,
        int $windowSeconds = 60,
        public bool $allowTauriWebviewOrigins = true,
        int $backdateMaxSeconds = BackdateLimits::MAX_SECONDS,
        int $clockSkewSeconds = 120,
        public bool $allowIdleReassign = true,
        bool $idleCheckEnabled = true,
        int $idleCheckThresholdMinutes = 10,
        int $idleCheckAnswerTimeoutMinutes = 5,
        bool $idleCheckUserCanDisable = false,
    ) {
        $this->allowedOrigins = array_values(array_unique($allowedOrigins));
        $this->windowSeconds  = max(1, $windowSeconds);
        $this->backdate       = new BackdateLimits($backdateMaxSeconds, $clockSkewSeconds);
        $this->idleCheck      = new IdleCheckSettings($idleCheckEnabled, $idleCheckThresholdMinutes, $idleCheckAnswerTimeoutMinutes, $idleCheckUserCanDisable);
    }
}
