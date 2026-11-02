<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;

/**
 * Host check of the credential behind a session token, run on **every** authenticated client API request (contract
 * v0.2 §1/§6). Return false when the credential was revoked or expired, the user is disabled, the organisation is
 * suspended, or the user signed out everywhere after {@see ClientToken::getIssuedAt()}. Keep it cheap (the host may
 * cache the answer up to 60 s). Default: {@see AlwaysActiveClientCredentialState}.
 */
interface ClientCredentialStateInterface
{
    /**
     * Whether the token's credential (and user) may still use the API.
     *
     * @param ClientToken $token Valid, not expired session token (credentialId, issuedAt, clientType, user, scopes)
     */
    public function isActive(ClientToken $token): bool;
}
