<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function str_starts_with;

/**
 * Cache pool that plays a concurrent request: the second read of an `Idempotency-Key` item finds `$raced` stored, as if
 * another request with the same key had finished in between (audit GG-L1).
 */
final class RacingCachePool implements CacheItemPoolInterface
{
    /** @var array<string, mixed>|null value stored "concurrently" before the second read */
    public ?array $raced = null;

    private readonly ArrayAdapter $inner;

    /** @var array<string, int> */
    private array $reads = [];

    public function __construct()
    {
        $this->inner = new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        if (str_starts_with($key, 'nowo_time_track_idem_') && ($this->reads[$key] = ($this->reads[$key] ?? 0) + 1) === 2 && $this->raced !== null) {
            $item = $this->inner->getItem($key);
            $item->set($this->raced);
            $this->inner->save($item);
        }

        return $this->inner->getItem($key);
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        $this->reads = [];

        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
