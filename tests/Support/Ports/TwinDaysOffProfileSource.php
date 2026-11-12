<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Tests\Stub\LeaveDaysOffProfileProvider;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/** Twin of {@see LeaveDaysOffProfileProvider} (same state, the days-off extra). */
final class TwinDaysOffProfileSource implements ClientUserProfileSourceInterface, ClientUserDaysOffSourceInterface
{
    public int $calls = 0;

    public function __construct(private readonly LeaveDaysOffProfileProvider $inner)
    {
    }

    public function displayNameOf(TimeTrackUserRef $user): ?string
    {
        ++$this->calls;

        return $this->inner->getDisplayName(TwinPorts::user($user));
    }

    public function workingHoursOf(TimeTrackUserRef $user): ?array
    {
        ++$this->calls;

        return $this->inner->getWorkingHours(TwinPorts::user($user));
    }

    public function daysOffOf(TimeTrackUserRef $user, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        ++$this->calls;

        return $this->inner->getDaysOff(TwinPorts::user($user), $from, $to);
    }
}
