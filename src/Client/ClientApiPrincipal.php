<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Authenticated caller of a client API request: the token user and the session token.
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
