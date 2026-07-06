<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_array;

final readonly class ClientLoginRateLimiter
{
    private const CACHE_PREFIX = 'nowo_time_track_client_login_';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $maxAttempts,
        private int $intervalSeconds,
        private bool $enabled,
    ) {
    }

    public function isLimited(string $clientIp, string $username): bool
    {
        if (!$this->enabled || $this->maxAttempts <= 0) {
            return false;
        }

        $item = $this->cache->getItem($this->cacheKey($clientIp, $username));

        if (!$item->isHit()) {
            return false;
        }

        $data = $item->get();

        return is_array($data) && (int) ($data['count'] ?? 0) >= $this->maxAttempts;
    }

    public function registerFailedAttempt(string $clientIp, string $username): void
    {
        if (!$this->enabled || $this->maxAttempts <= 0) {
            return;
        }

        $key           = $this->cacheKey($clientIp, $username);
        $item          = $this->cache->getItem($key);
        $data          = $item->isHit() && is_array($item->get()) ? $item->get() : ['count' => 0];
        $data['count'] = (int) ($data['count'] ?? 0) + 1;
        $item->set($data);
        $item->expiresAfter(max(60, $this->intervalSeconds));
        $this->cache->save($item);
    }

    public function reset(string $clientIp, string $username): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->cache->deleteItem($this->cacheKey($clientIp, $username));
    }

    private function cacheKey(string $clientIp, string $username): string
    {
        return self::CACHE_PREFIX . hash('sha256', $clientIp . '|' . mb_strtolower($username));
    }
}
