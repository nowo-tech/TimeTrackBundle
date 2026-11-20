<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use LogicException;
use Nowo\TimeTrackBundle\Dto\ClientSessionRecord;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Event\ClientSessionAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Repository\ClientSessionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_intersect;
use function is_string;

/**
 * Host entry point to end client sessions (contract v0.2 §1):
 *
 * - {@see self::revokeByCredential()} ends every session of a credential at once (the user deletes an app password /
 *   device);
 * - since 1.34.0, {@see self::revokeById()} ends **one** session by its id ("sign out this device"), authorised for its
 *   owner and `security.admin_roles`, then {@see ClientSessionAccessCheckEvent}.
 *
 * The per-request {@see ClientCredentialStateInterface} check covers everything else (disabled user, sign out
 * everywhere, ...). Stateless: safe in a FrankenPHP worker with kernel reset off.
 */
final readonly class ClientTokenRevoker
{
    private LoggerInterface $logger;

    /**
     * Receives the token store, the auditor and (1.34.0) the session store, the dispatcher of the access check and the
     * admin roles.
     *
     * @param ClientSessionRepositoryInterface|null $sessions Store of {@see self::revokeById()} (null: the token store when it implements it)
     * @param list<string> $adminRoles `security.admin_roles`: may revoke any session (no role hierarchy)
     */
    public function __construct(
        private CredentialClientTokenRepositoryInterface $tokens,
        private ?ClientApiAuditor $auditor = null,
        private ?ClientSessionRepositoryInterface $sessions = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private array $adminRoles = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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

    /**
     * Since 1.34.0: ends one client session (its Bearer token stops authenticating at once) as the actor.
     *
     * Granted for the session owner and for a user with one of `security.admin_roles`; then
     * {@see ClientSessionAccessCheckEvent} (`nowo_time_track.client_session.access_check`, action `revoke`) may grant or
     * deny. Idempotent: an unknown, malformed, expired-and-purged or already revoked id, and a session the actor may not
     * revoke, all answer {@see ClientSessionRevocation::NotFound} with nothing written — a caller learns nothing about a
     * session that is not theirs. A real revocation dispatches the audit event `session_revoked`
     * ({@see ClientApiAuditAction::SessionRevoked}, user = the owner, reason `owner` or `other`); a listener that throws
     * is logged and does not undo it.
     *
     * The write is one `DELETE` statement (nothing is flushed, cleared or reset; no entity is loaded into any manager).
     * Two concurrent calls for one session: one gets `Revoked`, the other `NotFound`. A lock conflict (deadlock, lock
     * wait timeout) propagates as the storage exception with nothing committed (REQ-RUNTIME-003 rule 6:
     * `CONFLICT_001`, retry safe); inside the host's open transaction the host's commit or rollback decides, as with any
     * other write of the bundle.
     *
     * @param ClientSessionId|string $sessionId The session id (`ClientToken::getId()`)
     * @param UserInterface $actor The user asking (owner or admin)
     *
     * @throws LogicException when the configured token store cannot look up sessions by id
     */
    public function revokeById(ClientSessionId|string $sessionId, UserInterface $actor): ClientSessionRevocation
    {
        $id = is_string($sessionId) ? ClientSessionId::tryFromString($sessionId) : $sessionId;
        if (!$id instanceof ClientSessionId) {
            return ClientSessionRevocation::NotFound;
        }
        $store   = $this->sessionStore();
        $session = $store->findSessionById($id->toString());
        if (!$session instanceof ClientSessionRecord) {
            return ClientSessionRevocation::NotFound;
        }
        $isOwner = UserIdResolver::idOf($actor) === $session->ownerId;
        if (!$this->isGranted($actor, $session, $isOwner)) {
            $this->logger->info('TimeTrack client session revocation refused.', ['bundle' => 'time_track', 'action' => 'client_session_revoke', 'reason' => 'forbidden']);

            return ClientSessionRevocation::NotFound;
        }
        // @igor-ignore - Request-scoped value or I/O delegate (repository call); no worker state.
        if ($store->removeSessionById($session->id) === 0) {
            // A concurrent call revoked it first (or the purge removed it): the same answer as an unknown id.
            return ClientSessionRevocation::NotFound;
        }

        try {
            $this->auditor?->recordForUserId(ClientApiAuditAction::SessionRevoked, $session->ownerId, $session->credentialId, $session->clientType, null, $isOwner ? 'owner' : 'other');
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack client session audit listener failed.', ['bundle' => 'time_track', 'action' => 'client_session_revoke', 'exception' => $exception::class]);
        }
        $this->logger->info('TimeTrack client session revoked.', ['bundle' => 'time_track', 'action' => 'client_session_revoke', 'client' => $session->clientType->value]);

        return ClientSessionRevocation::Revoked;
    }

    private function sessionStore(): ClientSessionRepositoryInterface
    {
        if ($this->sessions instanceof ClientSessionRepositoryInterface) {
            return $this->sessions;
        }
        if ($this->tokens instanceof ClientSessionRepositoryInterface) {
            return $this->tokens;
        }

        throw new LogicException('The TimeTrack token store does not implement ' . ClientSessionRepositoryInterface::class . ': it cannot revoke one session by its id.');
    }

    private function isGranted(UserInterface $actor, ClientSessionRecord $session, bool $isOwner): bool
    {
        $granted = $isOwner || array_intersect($this->adminRoles, $actor->getRoles()) !== [];
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return $granted;
        }
        $event = new ClientSessionAccessCheckEvent(TimeTrackUserRefs::fromUser($actor), $session->id, $session->ownerId, $session->credentialId, $session->clientType, ClientSessionAccessCheckEvent::ACTION_REVOKE, $granted);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK);

        return $event->isGranted();
    }
}
