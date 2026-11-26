<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Since 2.2.1 (audit ZBV-v-I2): optional companion of {@see ClientCredentialMinterInterface} /
 * {@see ClientFamilyCredentialMinterInterface}. A credential is minted when the user approves a device request; when the
 * token exchange is then refused (the account was disabled, or the scope family was unregistered, after the approval)
 * no session token ever uses it. When the configured `client_api.credential_issuer` also implements this interface, the
 * bundle asks it to revoke that credential, best effort, after the refusal is saved (an exception is logged and the
 * device still gets `access_denied`). Without it the credential stays in the host's list until the host revokes it.
 *
 * Must be idempotent and stateless (FrankenPHP worker mode); it must not throw for an unknown or already revoked id.
 */
interface OrphanedCredentialRevokerInterface
{
    /**
     * Revokes a credential no token was ever issued for.
     *
     * @param string $credentialId The id {@see ClientCredentialMinterInterface::mintForDevice()} or {@see ClientFamilyCredentialMinterInterface::mintForFamily()} returned
     * @param string $scopeFamily The scope family of the refused request (`time`, or a host family such as `chat`)
     */
    public function revokeOrphanedCredential(string $credentialId, string $scopeFamily): void;
}
