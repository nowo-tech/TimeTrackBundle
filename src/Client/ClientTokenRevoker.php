<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host entry point to end the sessions of a credential at once (contract v0.2 §1): call
 * {@see self::revokeByCredential()} when the user deletes an app password / device. The per-request
 * {@see ClientCredentialStateInterface} check covers everything else (disabled user, sign out everywhere, ...).
 */
final readonly class ClientTokenRevoker
{
    /**
     * Receives the token store and the auditor.
     */
    public function __construct(
        private CredentialClientTokenRepositoryInterface $tokens,
        private ?ClientApiAuditor $auditor = null,
    ) {
    }

    /**
     * Deletes every session token issued from the credential and returns how many were deleted.
     *
     * @param string $credentialId Host credential id (as passed to ClientAuthResult::success() or returned by the issuer)
     * @param UserInterface|null $user Owner, for the audit event
     */
    public function revokeByCredential(string $credentialId, ?UserInterface $user = null): int
    {
        if ($credentialId === '') {
            return 0;
        }

        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $count = $this->tokens->removeByCredentialId($credentialId);
        $this->auditor?->record(ClientApiAuditAction::CredentialRevoked, $user, $credentialId, null, null);

        return $count;
    }
}
