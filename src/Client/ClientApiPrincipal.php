<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Authenticated caller of a client API request: the token user and the session token. Built by the bundle's API guard
 * for its own controllers; not a host port (since 1.23.0 marked internal, REQ-ARCH-001 rule 3).
 *
 * @internal
 */
final readonly class ClientApiPrincipal
{
    /**
     * Pairs the user with the token.
     */
    public function __construct(
        public UserInterface $user,
        public ClientToken $token,
    ) {
    }
}
