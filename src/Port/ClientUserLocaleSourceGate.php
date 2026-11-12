<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleSourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see ClientUserLocaleSourceInterface} (1.23.0) where the bundle asks the deprecated
 * {@see ClientUserLocaleProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class ClientUserLocaleSourceGate implements ClientUserLocaleProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private ClientUserLocaleSourceInterface $source)
    {
    }

    /**
     * The source's locale for the user's reference.
     */
    public function getLocale(UserInterface $user): ?string
    {
        return $this->source->localeOf(TimeTrackUserRefs::fromUser($user));
    }
}
