<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_array;
use function is_int;
use function is_string;

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
     * Since 2.2.0 (audit ZBV-L3): the body hash bound to the tenant context the request named (unchanged without a
     * context), so the same key and body sent under another context is a mismatch, never a replay of the other
     * context's answer.
     */
    public static function contextHash(string $bodyHash, ?string $contextKey): string
    {
        return $contextKey === null ? $bodyHash : hash('sha256', $bodyHash . "\0context\0" . $contextKey);
    }

    /**
     * The stored answer of the key (`{status, body}`), `false` when the key was used with another body, null when new.
     * Since 1.27.0 an answer stored by {@see self::savePending()} and not confirmed yet also carries `pending` (the
     * nonce of the request that stored it).
     *
     * @return array{status: int, body: array<string, mixed>, pending?: string}|false|null
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

        $pending = $stored['pending'] ?? null;

        return ['status' => $stored['status'], 'body' => $body] + (is_string($pending) ? ['pending' => $pending] : []);
    }

    /**
     * Stores an answer for 24 h.
     *
     * @param array<string, mixed> $body
     */
    public function save(string $userId, string $key, string $bodyHash, int $status, array $body): void
    {
        $this->store($userId, $key, ['hash' => $bodyHash, 'status' => $status, 'body' => $body]);
    }

    /**
     * Since 1.27.0 (audit QO-L2): stores an answer written inside a transaction that has not committed yet, marked
     * with the nonce of the request that stored it. {@see self::confirm()} marks it committed after the commit;
     * {@see self::forget()} drops it when the commit failed.
     *
     * @param array<string, mixed> $body
     */
    public function savePending(string $userId, string $key, string $bodyHash, int $status, array $body, string $nonce): void
    {
        $this->store($userId, $key, ['hash' => $bodyHash, 'status' => $status, 'body' => $body, 'pending' => $nonce]);
    }

    /**
     * Since 1.27.0 (audit QO-L2): marks the pending answer of this request committed (replayed from then on whatever
     * happens to the entry it names). An answer another request stored meanwhile is left alone. Since 2.1.0 `$body`
     * replaces the stored body (resolved after the commit, e.g. with labels a host port gives, audit ZBQ-L5).
     *
     * @param array<string, mixed>|null $body The final body (null = keep the pending one)
     */
    public function confirm(string $userId, string $key, string $nonce, ?array $body = null): void
    {
        $item   = $this->cache->getItem($this->itemKey($userId, $key));
        $stored = $item->isHit() ? $item->get() : null;
        if (is_array($stored) && ($stored['pending'] ?? null) === $nonce) {
            unset($stored['pending']);
            if ($body !== null) {
                $stored['body'] = $body;
            }
            $this->store($userId, $key, $stored);
        }
    }

    /**
     * Since 1.27.0: drops the pending answer of this request (its transaction failed). Compare-and-delete (audit
     * QO-L2): an answer another request with the same key stored meanwhile is kept.
     */
    public function forget(string $userId, string $key, string $nonce): void
    {
        $item   = $this->cache->getItem($this->itemKey($userId, $key));
        $stored = $item->isHit() ? $item->get() : null;
        if (is_array($stored) && ($stored['pending'] ?? null) === $nonce) {
            $this->cache->deleteItem($this->itemKey($userId, $key));
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function store(string $userId, string $key, array $value): void
    {
        $item = $this->cache->getItem($this->itemKey($userId, $key));
        $item->set($value);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function itemKey(string $userId, string $key): string
    {
        return self::PREFIX . hash('sha256', $userId . "\0" . $key);
    }
}
