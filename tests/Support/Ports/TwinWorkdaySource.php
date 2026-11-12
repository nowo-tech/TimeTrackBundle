<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;

/** Twin of {@see StubWorkdayProvider} (same state). */
final class TwinWorkdaySource implements WorkdaySourceInterface
{
    public int $calls = 0;

    public function __construct(private readonly StubWorkdayProvider $inner)
    {
    }

    public function todayFor(TimeTrackUserRef $user): ?WorkdayState
    {
        ++$this->calls;

        return $this->inner->today(TwinPorts::user($user));
    }

    public function actFor(TimeTrackUserRef $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
    {
        ++$this->calls;

        return $this->inner->act(TwinPorts::user($user), $action, $occurredAt, $idempotencyKey, $client);
    }
}
