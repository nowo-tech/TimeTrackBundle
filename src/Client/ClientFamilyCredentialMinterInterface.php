<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): a {@see ClientCredentialMinterInterface} that also knows device-flow scope families
 * (same `client_api.credential_issuer` option). When the configured minter implements it, the bundle calls
 * {@see self::mintForFamily()} for **every** approved device request (the `time` family too), so the host can store the
 * family and the device key next to its credential and list one row per device with one badge per family, each revocable
 * on its own ({@see ClientTokenRevoker::revokeByCredential()}).
 *
 * A host that registers a family other than `time` must configure a minter implementing this interface: approving such
 * a request with a plain {@see ClientCredentialMinterInterface} fails (nothing is issued).
 */
interface ClientFamilyCredentialMinterInterface extends ClientCredentialMinterInterface
{
    /**
     * Creates a credential for the device and returns its id (stored on the session tokens, max 191 characters).
     */
    public function mintForFamily(DeviceCredentialRequest $request): string;
}
