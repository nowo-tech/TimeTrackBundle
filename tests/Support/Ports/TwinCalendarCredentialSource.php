<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialSourceInterface;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\ValueObject\CalendarConnectionRef;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use RuntimeException;

use function in_array;

/** Twin of {@see StubCalendarCredentialProvider}: the same rules on the connection snapshot, the stub's state. */
final class TwinCalendarCredentialSource implements CalendarCredentialSourceInterface
{
    public int $calls = 0;

    public function __construct(private readonly StubCalendarCredentialProvider $inner)
    {
    }

    public function credentialOf(TimeTrackUserRef $user, CalendarConnectionRef $connection): ?CalendarCredential
    {
        ++$this->calls;
        TwinPorts::user($user);
        if (in_array($connection->id, $this->inner->failFor, true)) {
            throw new RuntimeException('token store down');
        }

        return $this->inner->hasToken ? new CalendarCredential('fake-token-' . $connection->provider->value) : null;
    }

    public function revokeFor(TimeTrackUserRef $user, CalendarConnectionRef $connection): void
    {
        ++$this->calls;
        TwinPorts::user($user);
        $this->inner->revoked[] = $connection->id;
    }

    public function connectUrlFor(TimeTrackUserRef $user, string $provider, string $scopeKey): ?string
    {
        ++$this->calls;
        TwinPorts::user($user);

        return $this->inner->connectUrls[$provider] ?? null;
    }
}
