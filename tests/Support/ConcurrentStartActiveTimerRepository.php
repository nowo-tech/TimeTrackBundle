<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Repository\ConditionalActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use RuntimeException;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_shift;

/**
 * In-memory store with one running timer per user (the unique index) and scripted concurrent starts: each racer queued
 * with {@see self::raceNextSave()} inserts its own timer for the same user just before the next save, which then hits the
 * unique index, as when a client-API start committed between the read and the insert.
 */
final class ConcurrentStartActiveTimerRepository implements ConditionalActiveTimerRepositoryInterface
{
    /** @var array<string, ActiveTimer> user id => timer */
    private array $byUser = [];

    /** @var list<array{user: UserInterface, taskId: string}> */
    private array $racers = [];

    public int $saves = 0;

    public function raceNextSave(UserInterface $user, string $taskId = 'task-3'): void
    {
        $this->racers[] = ['user' => $user, 'taskId' => $taskId];
    }

    /**
     * Inserts a timer as a concurrent request would (no racer, no unique check).
     */
    public function insertDirectly(ActiveTimer $timer): void
    {
        $this->byUser[$this->ownerId($timer)] = $timer;
    }

    public function save(ActiveTimer $timer): void
    {
        ++$this->saves;
        $racer = array_shift($this->racers);
        if ($racer !== null) {
            $this->insertDirectly(new ActiveTimer($racer['user'], $racer['taskId'], 'Racer', 'board-2', ClientType::Desktop));
        }
        $userId  = $this->ownerId($timer);
        $current = $this->byUser[$userId] ?? null;
        if ($current !== null && $current->getId() !== $timer->getId()) {
            throw new UniqueConstraintViolationException(new class('Duplicate entry for key time_track_active_timers_user_unique') extends RuntimeException implements DriverException {
                public function getSQLState(): string
                {
                    return '23000';
                }
            }, null);
        }
        $this->byUser[$userId] = $timer;
    }

    public function remove(ActiveTimer $timer): void
    {
        $this->removeIfPresent($timer, static function (): void {
        });
    }

    public function removeIfPresent(ActiveTimer $timer, callable $onRemoved): bool
    {
        $userId = $this->ownerId($timer);
        if (($this->byUser[$userId] ?? null)?->getId() !== $timer->getId()) {
            return false;
        }
        unset($this->byUser[$userId]);
        $onRemoved();

        return true;
    }

    private function ownerId(ActiveTimer $timer): string
    {
        $user = $timer->getUser();
        if (!$user instanceof UserInterface) {
            throw new LogicException('Active timer user must implement UserInterface.');
        }

        return UserIdResolver::getId($user);
    }

    public function findByUserId(string $userId): ?ActiveTimer
    {
        return $this->byUser[$userId] ?? null;
    }
}
