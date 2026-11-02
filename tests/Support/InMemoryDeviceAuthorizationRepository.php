<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\Repository\DeviceAuthorizationRepositoryInterface;
use Psr\Clock\ClockInterface;

use function array_values;

final class InMemoryDeviceAuthorizationRepository implements DeviceAuthorizationRepositoryInterface
{
    /** @var array<string, DeviceAuthorization> */
    private array $items = [];

    /** @var array<string, bool> */
    private array $consumed = [];

    public function __construct(private readonly ?ClockInterface $clock = null)
    {
    }

    public function save(DeviceAuthorization $authorization): void
    {
        $this->items[$authorization->getId()] = $authorization;
    }

    public function remove(DeviceAuthorization $authorization): void
    {
        unset($this->items[$authorization->getId()]);
    }

    public function findByDeviceCodeHash(string $deviceCodeHash): ?DeviceAuthorization
    {
        foreach ($this->items as $item) {
            if ($item->getDeviceCodeHash() === $deviceCodeHash) {
                return $item;
            }
        }

        return null;
    }

    public function findPendingByUserCodeHash(string $userCodeHash): ?DeviceAuthorization
    {
        foreach ($this->items as $item) {
            if ($item->getUserCodeHash() === $userCodeHash && $this->isPending($item)) {
                return $item;
            }
        }

        return null;
    }

    public function findPendingById(string $id): ?DeviceAuthorization
    {
        $item = $this->items[$id] ?? null;

        return $item !== null && $this->isPending($item) ? $item : null;
    }

    public function markConsumed(DeviceAuthorization $authorization): bool
    {
        if (isset($this->consumed[$authorization->getId()]) || $authorization->getStatus() !== DeviceAuthorizationStatus::Approved) {
            return false;
        }

        $this->consumed[$authorization->getId()] = true;

        return true;
    }

    public function purgeExpired(): int
    {
        $purged = 0;
        foreach ($this->items as $id => $item) {
            if ($item->isExpired($this->now())) {
                unset($this->items[$id]);
                ++$purged;
            }
        }

        return $purged;
    }

    /**
     * @return list<DeviceAuthorization>
     */
    public function all(): array
    {
        return array_values($this->items);
    }

    private function isPending(DeviceAuthorization $item): bool
    {
        return $item->getStatus() === DeviceAuthorizationStatus::Pending && !$item->isExpired($this->now());
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
