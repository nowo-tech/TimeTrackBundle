<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;

use function array_shift;
use function array_values;

/**
 * Since 2.1.0: an in-memory timer store that runs a scripted hook before each read (one hook per read, in order; null =
 * no hook), so a unit test can replace or remove the running timer between two reads of one call (a concurrent switch).
 */
final class HookedActiveTimerRepository implements ActiveTimerRepositoryInterface
{
    /** @var array<int, (callable(self): void)|null> */
    private array $beforeFind = [];

    /** Number of reads so far. */
    public int $finds = 0;

    public function __construct(private readonly InMemoryActiveTimerRepository $inner = new InMemoryActiveTimerRepository())
    {
    }

    /**
     * Scripts the hooks of the next reads.
     *
     * @param (callable(self): void)|null ...$hooks
     */
    public function beforeFinds(?callable ...$hooks): void
    {
        $this->beforeFind = array_values($hooks);
    }

    /**
     * Saves without running a hook.
     */
    public function save(ActiveTimer $timer): void
    {
        $this->inner->save($timer);
    }

    /**
     * Removes without running a hook.
     */
    public function remove(ActiveTimer $timer): void
    {
        $this->inner->remove($timer);
    }

    /**
     * Runs the next hook, then reads.
     */
    public function findByUserId(string $userId): ?ActiveTimer
    {
        ++$this->finds;
        $hook = array_shift($this->beforeFind);
        if ($hook !== null) {
            $hook($this);
        }

        return $this->inner->findByUserId($userId);
    }
}
