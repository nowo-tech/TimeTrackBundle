<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_int;
use function max;

/**
 * Since 1.26.0 (audit PM-L5, Beacon): the app seconds already accepted per worker and slot, across batches, so the
 * `app` seconds of one (user, slot) never exceed `slotMinutes × 60`. Kept in the shared rate-limit pool
 * (`client_api.rate_limit.cache_pool`) for 25 hours, never in process memory (FrankenPHP worker mode); keys are SHA-256
 * digests. PSR-6 has no atomic increment: two concurrent batches of the same slot may both fit; the host's own
 * per-slot sum is the backstop.
 */
final readonly class MonitoringActivityBudget
{
    private const PREFIX = 'nowo_time_track_monitoring_budget_';

    /** Longer than the 24 h buffer age plus the clock skew. */
    private const TTL_SECONDS = 90_000;

    /**
     * Receives the shared pool.
     */
    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    /**
     * Seconds already accepted for the worker's slot.
     */
    public function used(string $userId, int $slot): int
    {
        $item = $this->cache->getItem($this->key($userId, $slot));
        $used = $item->isHit() ? $item->get() : 0;

        return is_int($used) ? max(0, $used) : 0;
    }

    /**
     * Adds accepted seconds to the worker's slot.
     */
    public function add(string $userId, int $slot, int $seconds): void
    {
        $item = $this->cache->getItem($this->key($userId, $slot));
        $item->set($this->used($userId, $slot) + $seconds);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function key(string $userId, int $slot): string
    {
        return self::PREFIX . hash('sha256', $userId . "\0" . $slot);
    }
}
