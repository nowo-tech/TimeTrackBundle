<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_array;
use function is_int;

/**
 * Since 1.13: `Idempotency-Key` replays of the client API (contract §10.4): the same key and body from the same user
 * get the same answer for 24 h; the same key with another body is refused (`idempotency_mismatch`). Keys are hashed with
 * the user id; only the response (no request body) is stored.
 */
final readonly class ClientIdempotencyStore
{
    /** How long a response is replayed. */
    public const TTL_SECONDS = 86_400;

    /** Longest key. */
    public const MAX_KEY_LENGTH = 64;

    private const PREFIX = 'nowo_time_track_idem_';

    /**
     * Receives the cache pool (`client_api.rate_limit.cache_pool`).
     */
    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    /**
     * The stored answer of the key (`{status, body}`), `false` when the key was used with another body, null when new.
     *
     * @return array{status: int, body: array<string, mixed>}|false|null
     */
    public function find(string $userId, string $key, string $bodyHash): array|false|null
    {
        $item = $this->cache->getItem($this->itemKey($userId, $key));
        if (!$item->isHit()) {
            return null;
        }
        $stored = $item->get();
        if (!is_array($stored) || !is_int($stored['status'] ?? null) || !is_array($stored['body'] ?? null)) {
            return null;
        }
        if (($stored['hash'] ?? null) !== $bodyHash) {
            return false;
        }

        /** @var array<string, mixed> $body */
        $body = $stored['body'];

        return ['status' => $stored['status'], 'body' => $body];
    }

    /**
     * Stores an answer for 24 h.
     *
     * @param array<string, mixed> $body
     */
    public function save(string $userId, string $key, string $bodyHash, int $status, array $body): void
    {
        $item = $this->cache->getItem($this->itemKey($userId, $key));
        $item->set(['hash' => $bodyHash, 'status' => $status, 'body' => $body]);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function itemKey(string $userId, string $key): string
    {
        return self::PREFIX . hash('sha256', $userId . "\0" . $key);
    }
}
