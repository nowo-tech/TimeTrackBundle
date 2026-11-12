<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialProviderInterface;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialSourceInterface;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\ValueObject\CalendarConnectionRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see CalendarCredentialSourceInterface} (1.23.0) where the bundle asks the deprecated
 * {@see CalendarCredentialProviderInterface}: users become references, connections {@see CalendarConnectionRef}
 * snapshots. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class CalendarCredentialSourceGate implements CalendarCredentialProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private CalendarCredentialSourceInterface $source)
    {
    }

    /**
     * The source's credential for the user's reference and the connection snapshot.
     */
    public function credentialFor(UserInterface $user, CalendarConnection $connection): ?CalendarCredential
    {
        return $this->source->credentialOf(TimeTrackUserRefs::fromUser($user), CalendarConnectionRef::fromConnection($connection));
    }

    /**
     * Asks the source to revoke the connection's grant.
     */
    public function revoke(UserInterface $user, CalendarConnection $connection): void
    {
        $this->source->revokeFor(TimeTrackUserRefs::fromUser($user), CalendarConnectionRef::fromConnection($connection));
    }

    /**
     * The source's connect URL for the user's reference.
     */
    public function connectUrl(UserInterface $user, string $provider, string $scopeKey): ?string
    {
        return $this->source->connectUrlFor(TimeTrackUserRefs::fromUser($user), $provider, $scopeKey);
    }
}
