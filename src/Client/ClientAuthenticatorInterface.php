<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

interface ClientAuthenticatorInterface
{
    public function authenticate(string $username, string $password): ?ClientAuthResult;
}
