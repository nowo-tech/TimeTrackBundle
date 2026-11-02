<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Checks the credentials sent by the browser extension or the desktop app (hosts may plug app passwords, LDAP, ...).
 */
interface ClientAuthenticatorInterface
{
    /**
     * Authenticates a client login.
     */
    public function authenticate(string $username, string $password): ?ClientAuthResult;
}
