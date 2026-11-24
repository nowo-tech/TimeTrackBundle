<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdayHistorySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;

use function array_map;
use function in_array;

/**
 * Since 1.41.0 (D11): a framework-free register with fixed days that answers today only (`todayFor`), as before
 * 1.41; {@see self::withHistory()} is the same register that also implements {@see WorkdayHistorySourceInterface}.
 * Users whose id is in `$off` have no register.
 */
final class FixedWorkdayRegister implements WorkdaySourceInterface
{
    /** @var array<string, WorkdayState> */
    public array $days = [];

    /** @var array<string, array<string, WorkdayState>> user id => days (overrides `$days` for that user) */
    public array $byUser = [];

    /** @var list<string> */
    public array $off = [];

    public int $calls = 0;

    public function __construct(public string $today)
    {
    }

    /**
     * @param list<array{0: string, 1: string, 2: string|null}> $segments [type, start, end]
     */
    public function day(string $date, string $status, array $segments, string $timezone = 'UTC', ?string $userId = null): self
    {
        $state = new WorkdayState($status, $date, $timezone, array_map(static fn (array $s): array => ['type' => $s[0], 'start' => $s[1], 'end' => $s[2], 'source' => 'desktop'], $segments));
        if ($userId === null) {
            $this->days[$date] = $state;
        } else {
            $this->byUser[$userId][$date] = $state;
        }

        return $this;
    }

    public function todayFor(TimeTrackUserRef $user): ?WorkdayState
    {
        return $this->dayOf($user, $this->today);
    }

    public function actFor(TimeTrackUserRef $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
    {
        throw new LogicException('Read only.');
    }

    public function dayOf(TimeTrackUserRef $user, string $date): ?WorkdayState
    {
        ++$this->calls;

        $id = (string) $user->id;

        return in_array($id, $this->off, true) ? null : (($this->byUser[$id] ?? $this->days)[$date] ?? null);
    }

    /**
     * The same register with {@see WorkdayHistorySourceInterface}.
     */
    public function withHistory(): WorkdaySourceInterface&WorkdayHistorySourceInterface
    {
        return new class($this) implements WorkdaySourceInterface, WorkdayHistorySourceInterface {
            public function __construct(private readonly FixedWorkdayRegister $inner)
            {
            }

            public function todayFor(TimeTrackUserRef $user): ?WorkdayState
            {
                return $this->inner->todayFor($user);
            }

            public function actFor(TimeTrackUserRef $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
            {
                return $this->inner->actFor($user, $action, $occurredAt, $idempotencyKey, $client);
            }

            public function dayFor(TimeTrackUserRef $user, string $date): ?WorkdayState
            {
                return $this->inner->dayOf($user, $date);
            }
        };
    }
}
