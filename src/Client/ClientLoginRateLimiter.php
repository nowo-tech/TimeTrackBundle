<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_array;
use function iterator_to_array;
use function max;
use function mb_strtolower;
use function trim;

/**
 * Failed client-login throttle with two buckets, like Symfony's DefaultLoginRateLimiter:
 * IP + username (`max_attempts`) and IP only (`max_attempts_per_ip`, default 5 × max_attempts),
 * so an IP that rotates usernames is blocked too.
 *
 * Counters live in a PSR-6 pool, which has no atomic increment: concurrent failures may lose
 * an increment. Both buckets are read in one getItems() call and committed together to keep
 * the window small; put a reverse-proxy / firewall rate limit in front for hard guarantees.
 */
final readonly class ClientLoginRateLimiter
{
    private const CACHE_PREFIX = 'nowo_time_track_client_login_';

    private const IP_CACHE_PREFIX = 'nowo_time_track_client_login_ip_';

    private int $maxAttemptsPerIp;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $maxAttempts,
        private int $intervalSeconds,
        private bool $enabled,
        ?int $maxAttemptsPerIp = null,
    ) {
        $this->maxAttemptsPerIp = $maxAttemptsPerIp ?? 5 * $maxAttempts;
    }

    public function isLimited(string $clientIp, string $username): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $items = $this->items($clientIp, $username);

        return $this->count($items[0]) >= $this->maxAttempts
            || ($this->maxAttemptsPerIp > 0 && $this->count($items[1]) >= $this->maxAttemptsPerIp);
    }

    public function registerFailedAttempt(string $clientIp, string $username): void
    {
        if (!$this->isActive()) {
            return;
        }

        foreach ($this->items($clientIp, $username) as $item) {
            $item->set(['count' => $this->count($item) + 1]);
            $item->expiresAfter(max(60, $this->intervalSeconds));
            $this->cache->saveDeferred($item);
        }
        $this->cache->commit();
    }

    /**
     * Clears the IP + username bucket after a successful login. The IP-only bucket is kept so a
     * valid account cannot be used to reset the per-IP budget; it expires after the interval.
     */
    public function reset(string $clientIp, string $username): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->cache->deleteItem($this->cacheKey($clientIp, $username));
    }

    private function isActive(): bool
    {
        return $this->enabled && $this->maxAttempts > 0;
    }

    /**
     * @return array{0: CacheItemInterface, 1: CacheItemInterface} [IP + username, IP only]
     */
    private function items(string $clientIp, string $username): array
    {
        $pairKey = $this->cacheKey($clientIp, $username);
        $ipKey   = self::IP_CACHE_PREFIX . hash('sha256', $clientIp);
        $items   = iterator_to_array($this->cache->getItems([$pairKey, $ipKey]));

        return [$items[$pairKey], $items[$ipKey]];
    }

    private function count(CacheItemInterface $item): int
    {
        if (!$item->isHit()) {
            return 0;
        }

        $data = $item->get();

        return is_array($data) ? (int) ($data['count'] ?? 0) : 0;
    }

    private function cacheKey(string $clientIp, string $username): string
    {
        return self::CACHE_PREFIX . hash('sha256', $clientIp . '|' . mb_strtolower(trim($username)));
    }
}
