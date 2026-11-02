<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Support\IpPrefix;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_array;
use function iterator_to_array;
use function max;
use function mb_strtolower;
use function trim;

/**
 * Failed client-login throttle with three buckets: IP + username (`max_attempts`), IP only
 * (`max_attempts_per_ip`, default 5 × max_attempts) so an IP that rotates usernames is blocked, and
 * username only (`max_attempts_per_username`, default 5 × max_attempts) so a client that rotates
 * addresses cannot guess one account's password without bound. IPv6 addresses count by their /64
 * ({@see IpPrefix::rateLimitKey()}).
 *
 * Counters live in a PSR-6 pool, which has no atomic increment: concurrent failures may lose
 * an increment. Both buckets are read in one getItems() call and committed together to keep
 * the window small; put a reverse-proxy / firewall rate limit in front for hard guarantees.
 */
final readonly class ClientLoginRateLimiter
{
    private const CACHE_PREFIX = 'nowo_time_track_client_login_';

    private const IP_CACHE_PREFIX = 'nowo_time_track_client_login_ip_';

    private const USERNAME_CACHE_PREFIX = 'nowo_time_track_client_login_user_';

    private int $maxAttemptsPerIp;

    private int $maxAttemptsPerUsername;

    /**
     * Configures the cache pool, the attempt budgets and the window.
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $maxAttempts,
        private int $intervalSeconds,
        private bool $enabled,
        ?int $maxAttemptsPerIp = null,
        ?int $maxAttemptsPerUsername = null,
    ) {
        $this->maxAttemptsPerIp       = $maxAttemptsPerIp ?? 5 * $maxAttempts;
        $this->maxAttemptsPerUsername = $maxAttemptsPerUsername ?? 5 * $maxAttempts;
    }

    /**
     * Whether the IP + username pair, the IP alone or the username alone has used up its failed-login budget.
     */
    public function isLimited(string $clientIp, string $username): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $items = $this->items($clientIp, $username);

        return $this->count($items[0]) >= $this->maxAttempts
            || ($this->maxAttemptsPerIp > 0 && $this->count($items[1]) >= $this->maxAttemptsPerIp)
            || ($this->maxAttemptsPerUsername > 0 && $this->count($items[2]) >= $this->maxAttemptsPerUsername);
    }

    /**
     * Counts a failed login in the three buckets.
     */
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
     * Clears the IP + username and username buckets after a successful login. The IP-only bucket is
     * kept so a valid account cannot be used to reset the per-IP budget; it expires after the interval.
     */
    public function reset(string $clientIp, string $username): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->cache->deleteItems([$this->cacheKey($clientIp, $username), $this->usernameKey($username)]);
    }

    private function isActive(): bool
    {
        return $this->enabled && $this->maxAttempts > 0;
    }

    /**
     * @return array{0: CacheItemInterface, 1: CacheItemInterface, 2: CacheItemInterface} [IP + username, IP only, username only]
     */
    private function items(string $clientIp, string $username): array
    {
        $pairKey = $this->cacheKey($clientIp, $username);
        $ipKey   = self::IP_CACHE_PREFIX . hash('sha256', IpPrefix::rateLimitKey($clientIp));
        $userKey = $this->usernameKey($username);
        $items   = iterator_to_array($this->cache->getItems([$pairKey, $ipKey, $userKey]));

        return [$items[$pairKey], $items[$ipKey], $items[$userKey]];
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
        return self::CACHE_PREFIX . hash('sha256', IpPrefix::rateLimitKey($clientIp) . '|' . mb_strtolower(trim($username)));
    }

    private function usernameKey(string $username): string
    {
        return self::USERNAME_CACHE_PREFIX . hash('sha256', mb_strtolower(trim($username)));
    }
}
