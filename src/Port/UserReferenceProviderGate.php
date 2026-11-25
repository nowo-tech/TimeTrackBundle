<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\UserReferenceResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see UserReferenceProviderInterface} (1.23.0) where the bundle asks its internal shape
 * {@see UserReferenceResolverInterface} (the 1.x interface of that name was removed in 2.0). Exceptions propagate. Stateless.
 *
 * @internal
 */
final readonly class UserReferenceProviderGate implements UserReferenceResolverInterface
{
    /**
     * Wraps the host provider.
     */
    public function __construct(private UserReferenceProviderInterface $provider)
    {
    }

    /**
     * The provider's stored object for the user's reference.
     */
    public function reference(UserInterface $user): object
    {
        return $this->provider->referenceFor(TimeTrackUserRefs::fromUser($user));
    }
}
