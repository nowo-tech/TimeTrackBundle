<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

/**
 * Since 1.26.0: `client_api.monitoring` settings — the upload route of the signed URLs and the per-token rate limits of
 * the monitoring routes (10-minute windows, contract §24.3).
 */
final readonly class MonitoringSettings
{
    /** Rate-limit window of the monitoring routes, in seconds. */
    public const WINDOW_SECONDS = 600;

    /** Lifetime of a signed upload URL, in seconds (contract: 120 s). */
    public const URL_TTL_SECONDS = 120;

    /** Accepted clock skew of `takenAt` / `slot`, in seconds (contract: ± 120 s). */
    public const CLOCK_SKEW_SECONDS = 120;

    /** Largest screenshot accepted, in bytes (1.5 MiB). */
    public const MAX_BYTES = 1_572_864;

    /** Most items of one activity batch. */
    public const MAX_ACTIVITY_ITEMS = 200;

    /** How long an intent row (and its `Idempotency-Key` replay) is kept, in seconds. */
    public const INTENT_RETENTION_SECONDS = 86_400;

    /**
     * @param string $uploadRoute Route name of `PUT monitoring/uploads/{uploadId}`
     * @param int $intentsPer10Min `POST screenshots/intent` (and `screenshots/discarded`, `DELETE screenshots/{id}`) per token (0 = off)
     * @param int $uploadsPer10Min `PUT uploads/{uploadId}` per token (0 = off)
     * @param int $activityPer10Min `POST activity` per token (0 = off)
     * @param string|null $uploadBaseUrl Since audit PM-L2: `https://host[:port]` the signed URLs start with (null = the request's scheme and host)
     */
    public function __construct(
        public string $uploadRoute = 'nowo_time_track_api_monitoring_upload',
        public int $intentsPer10Min = 6,
        public int $uploadsPer10Min = 6,
        public int $activityPer10Min = 30,
        public ?string $uploadBaseUrl = null,
    ) {
    }
}
