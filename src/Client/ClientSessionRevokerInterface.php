<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use LogicException;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Event\ClientSessionAccessCheckEvent;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Since 1.40.0 (Beacon, REQ-ARCH-001 rule 3): ends client sessions (the Bearer tokens of the desktop app and the browser
 * extension) by ids, without a Symfony user object and without the host removing `ClientToken` rows through its own
 * entity manager. The deletes run on the bundle's entity manager (private or shared) as plain `DELETE` statements: no
 * entity is loaded into any manager, nothing is flushed, cleared or reset. Public service (service id = this interface,
 * implemented by {@see ClientTokenRevoker}). Not deprecated: 2.0 keeps it.
 *
 * Stateless (FrankenPHP worker mode, kernel reset false).
 */
interface ClientSessionRevokerInterface
{
    /**
     * Ends one client session as the actor: its token stops authenticating at once.
     *
     * Granted for the session owner (the actor's id equals the session's owner id, byte for byte) and for an actor whose
     * reference carries one of `security.admin_roles` (a bare id carries no roles); then
     * {@see ClientSessionAccessCheckEvent} (action `revoke`) may grant or deny. Idempotent and without an oracle: an
     * unknown, malformed, purged or already revoked id, a reference whose user object has another id, and a session the
     * actor may not revoke all answer {@see ClientSessionRevocation::NotFound} with nothing written. A real revocation
     * dispatches the audit event `session_revoked` (user = the owner, reason `owner` or `other`), as `revokeById()` does.
     *
     * Two concurrent calls for one session: one gets `Revoked`, the other `NotFound`. Inside a transaction you opened on
     * the same connection the delete is part of it (your commit or rollback decides).
     *
     * @param ClientSessionId|string $sessionId The session id (`ClientSessionView::$id`)
     * @param int|string|TimeTrackUserRef $actor The user asking: a user id or a reference (roles for the admin rule)
     *
     * @throws LogicException When the configured token store cannot look up sessions by id
     * @throws TransientStorageFailure When the storage failed transiently (deadlock, lock wait timeout): nothing was committed, retry
     */
    public function revokeSession(ClientSessionId|string $sessionId, TimeTrackUserRef|string|int $actor): ClientSessionRevocation;

    /**
     * Ends every client session of a user ("sign out everywhere") and returns how many were ended. The host authorises
     * the call. Idempotent.
     *
     * @param string $userId The user id, compared byte for byte
     *
     * @throws LogicException When the configured token store cannot delete a user's sessions
     * @throws TransientStorageFailure When the storage failed transiently: nothing was committed, retry
     */
    public function revokeAllForUser(string $userId): int;
}
