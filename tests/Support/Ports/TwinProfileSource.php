<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/** Twin of {@see MutableProfileProvider} (same state, no extras). */
final class TwinProfileSource implements ClientUserProfileSourceInterface
{
    public int $calls = 0;

    public function __construct(private readonly MutableProfileProvider $inner)
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
}
