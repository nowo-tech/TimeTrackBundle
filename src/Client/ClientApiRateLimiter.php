<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function hash;
use function intdiv;
use function is_int;
use function max;

/**
 * Fixed-window request counters of the client API (per token, per IP, per device IP; audit throttling) on a
 * host-configurable PSR-6 pool (`client_api.rate_limit.cache_pool`; Beacon: Redis). Keys are SHA-256 digests, never the
 * token or the IP. PSR-6 has no atomic increment: under heavy concurrency a few hits may be lost; keep a reverse-proxy
 * limit in front for hard guarantees.
 */
final readonly class ClientApiRateLimiter
{
    private const CACHE_PREFIX = 'nowo_time_track_api_';

    private ClockInterface $clock;

    /**
     * Receives the cache pool and the clock.
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Counts a hit in the bucket and returns 0 when allowed, or the seconds until the window ends (Retry-After).
     *
     * @param string $bucket Bucket name (`token`, `ip`, `device_ip`, `audit`)
     * @param string $key Bucket key (hashed before use)
     * @param int $limit Hits allowed per window (0 or less = unlimited)
     * @param int $windowSeconds Window length in seconds
     */
    public function hit(string $bucket, string $key, int $limit, int $windowSeconds): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $windowSeconds = max(1, $windowSeconds);
        $now           = $this->clock->now()->getTimestamp();
        $window        = intdiv($now, $windowSeconds);
        $item          = $this->cache->getItem(self::CACHE_PREFIX . $bucket . '_' . hash('sha256', $key) . '_' . $window);
        $count         = $item->isHit() && is_int($item->get()) ? $item->get() : 0;
        $retryAfter    = max(1, ($window + 1) * $windowSeconds - $now);

        if ($count >= $limit) {
            return $retryAfter;
        }

        $item->set($count + 1);
        $item->expiresAfter($retryAfter);
        $this->cache->save($item);

        return 0;
    }
}
