<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkdayRegister;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdayHistorySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;

/**
 * Since 1.41.0 (D11): container service of the functional tests — a register with fixed days that also answers earlier
 * days ({@see WorkdayHistorySourceInterface}). Tests fill {@see self::$register}.
 */
final class StubWorkdayHistorySource implements WorkdaySourceInterface, WorkdayHistorySourceInterface
{
    public FixedWorkdayRegister $register;

    public function __construct()
    {
        $this->register = new FixedWorkdayRegister('');
    }

    public function todayFor(TimeTrackUserRef $user): ?WorkdayState
    {
        return $this->register->todayFor($user);
    }

    public function actFor(TimeTrackUserRef $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
    {
        return $this->register->actFor($user, $action, $occurredAt, $idempotencyKey, $client);
    }

    public function dayFor(TimeTrackUserRef $user, string $date): ?WorkdayState
    {
        return $this->register->dayOf($user, $date);
    }
}
