<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientType;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): the caller of another bundle's client API, authenticated with a device-flow token of
 * that API's scope family ({@see ClientFamilyTokenAuthenticator}). A host maps it to the other bundle's own principal
 * (e.g. ChatKit's `ChatClientPrincipal`). Built per request, never kept. Value object.
 */
final readonly class ClientFamilyPrincipal
{
    /**
     * @param string $userId Token user id (the string form of the host user's id)
     * @param object $user The host user object the token belongs to (refreshed for this request)
     * @param string $scopeFamily Scope family of the token
     * @param list<string> $scopes Granted scope values of that family
     * @param string $tokenId Session id (what `ClientTokenRevoker::revokeById()` takes)
     * @param string|null $credentialId Host credential the token was issued from
     * @param ClientType $clientType Client that holds the token
     * @param string|null $deviceKey Device key shared by the device's tokens of every family
     * @param string|null $contextKey Tenant context the request named and the host policy allowed (null = none)
     */
    public function __construct(
        public string $userId,
        public object $user,
        public string $scopeFamily,
        public array $scopes,
        public string $tokenId,
        public ?string $credentialId,
        public ClientType $clientType,
        public ?string $deviceKey,
        public ?string $contextKey,
    ) {
    }

    /**
     * Whether the token grants the scope.
     */
    public function hasScope(string $scope): bool
    {
        foreach ($this->scopes as $granted) {
            if ($granted === $scope) {
                return true;
            }
        }

        return false;
    }
}
