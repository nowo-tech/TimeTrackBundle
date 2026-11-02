<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Repository\ConditionalActiveTimerRepositoryInterface;

use function array_shift;
use function array_values;

/**
 * In-memory store with scripted stale reads: each `true` passed to {@see staleReads()} makes one read return the timer
 * seen at that moment, as a request that loaded it before another request committed its delete.
 */
final class RacingActiveTimerRepository implements ConditionalActiveTimerRepositoryInterface
{
    /** @var array<string, ActiveTimer> */
    private array $byId = [];

    private ?ActiveTimer $snapshot = null;

    /** @var list<bool> */
    private array $script = [];

    public function staleReads(bool ...$script): void
    {
        $this->snapshot = $this->current();
        $this->script   = array_values($script);
    }

    public function save(ActiveTimer $timer): void
    {
        $this->byId[$timer->getId()] = $timer;
    }

    public function remove(ActiveTimer $timer): void
    {
        unset($this->byId[$timer->getId()]);
    }

    public function findByUserId(string $userId): ?ActiveTimer
    {
        return array_shift($this->script) === true ? $this->snapshot : $this->current();
    }

    public function removeIfPresent(ActiveTimer $timer, callable $onRemoved): bool
    {
        if (!isset($this->byId[$timer->getId()])) {
            return false;
        }

        unset($this->byId[$timer->getId()]);
        $onRemoved();

        return true;
    }

    private function current(): ?ActiveTimer
    {
        return array_values($this->byId)[0] ?? null;
    }
}
