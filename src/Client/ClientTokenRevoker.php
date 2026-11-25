<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use LogicException;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Dto\ClientSessionRecord;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientSessionRevocation;
use Nowo\TimeTrackBundle\Event\ClientSessionAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Repository\ClientSessionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\ClientSessionId;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TransactionContract\TransientStorageFailure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_intersect;
use function is_a;
use function is_int;
use function is_string;

/**
 * Host entry point to end client sessions (contract v0.2 §1):
 *
 * - {@see self::revokeByCredential()} ends every session of a credential at once (the user deletes an app password /
 *   device);
 * - since 1.34.0, {@see self::revokeById()} ends **one** session by its id ("sign out this device"), authorised for its
 *   owner and `security.admin_roles`, then {@see ClientSessionAccessCheckEvent};
 * - since 1.40.0, {@see self::revokeSession()} does the same with the actor given by id or {@see TimeTrackUserRef}
 *   ({@see ClientSessionRevokerInterface}, no Symfony user object).
 *
 * The per-request {@see ClientCredentialStateInterface} check covers everything else (disabled user, sign out
 * everywhere, ...). Stateless: safe in a FrankenPHP worker with kernel reset off.
 */
final readonly class ClientTokenRevoker implements ClientSessionRevokerInterface
{
    private LoggerInterface $logger;

    /**
     * Receives the token store, the auditor and (1.34.0) the session store, the dispatcher of the access check and the
     * admin roles.
     *
     * @param ClientSessionRepositoryInterface|null $sessions Store of {@see self::revokeById()} (null: the token store when it implements it)
     * @param list<string> $adminRoles `security.admin_roles`: may revoke any session (no role hierarchy)
     * @param string $userClass Since 1.40.0: `user_class` (the class of the reference built from a bare actor id)
     * @param list<class-string> $acceptedUserClasses Since 1.40.1: `accepted_user_classes` (with `user_class`, the classes a
     *                                                reference given to {@see self::revokeSession()} may name)
     */
    public function __construct(
        private CredentialClientTokenRepositoryInterface $tokens,
        private ?ClientApiAuditor $auditor = null,
        private ?ClientSessionRepositoryInterface $sessions = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private array $adminRoles = [],
        ?LoggerInterface $logger = null,
        private string $userClass = '',
        private array $acceptedUserClasses = [],
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
     * Two concurrent calls for one session: one gets `Revoked`, the other `NotFound`. With the Doctrine store a lock
     * conflict (deadlock, lock wait timeout) propagates as `Nowo\TransactionContract\TransientStorageFailure` (Retry-After
     * hint) with nothing committed (REQ-RUNTIME-003 rule 6: `CONFLICT_001`, retry safe); inside the host's open
     * transaction the host's commit or rollback decides, as with any other write of the bundle.
     *
     * @param ClientSessionId|string $sessionId The session id (`ClientToken::getId()`)
     * @param UserInterface $actor The user asking (owner or admin)
     *
     * @throws LogicException when the configured token store cannot look up sessions by id
     * @throws TransientStorageFailure when the storage failed transiently (deadlock, lock wait timeout): nothing was committed, retry
     */
    public function revokeById(ClientSessionId|string $sessionId, UserInterface $actor): ClientSessionRevocation
    {
        return $this->revoke($sessionId, TimeTrackUserRefs::fromUser($actor), UserIdResolver::idOf($actor), $actor->getRoles());
    }

    /**
     * Since 1.40.0: {@see self::revokeById()} with the actor given by id or {@see TimeTrackUserRef}
     * ({@see ClientSessionRevokerInterface::revokeSession()}). A bare id is the owner rule only (no roles); a reference
     * carries its role names for `security.admin_roles` (the user object's, when it carries one); a reference whose user
     * object has another id than the reference is answered `NotFound`.
     *
     * Since 1.40.1 (audit ZAN-5) a reference whose class is neither `user_class` nor one of `accepted_user_classes` (nor a
     * subclass of them; `''` = unknown is accepted) is answered `NotFound`: another principal (an API client, an admin
     * entity) whose id equals a user's id is not that user. A revoker built without `user_class` does not check it.
     *
     * **Roles of a reference without a user object are trusted as given** (`TimeTrackUserRef::ofId(…, $roles)`): build them
     * from the current security user at call time, never from a queued message, a cache or request input.
     *
     * @throws LogicException when the configured token store cannot look up sessions by id
     * @throws TransientStorageFailure when the storage failed transiently (deadlock, lock wait timeout): nothing was committed, retry
     */
    public function revokeSession(ClientSessionId|string $sessionId, TimeTrackUserRef|string|int $actor): ClientSessionRevocation
    {
        if (!$actor instanceof TimeTrackUserRef) {
            $id = is_int($actor) ? (string) $actor : $actor;
            if ($id === '' || !DoctrineUserReferenceResolver::isExactId($id)) {
                return ClientSessionRevocation::NotFound;
            }

            return $this->revoke($sessionId, TimeTrackUserRef::ofId($this->userClass, $id), $id, []);
        }
        $object = $actor->user();
        if ($actor->id === null || !$this->isUserClass($actor->userClass) || ($object !== null && UserIdResolver::idOf($object) !== $actor->id)) {
            return ClientSessionRevocation::NotFound;
        }

        return $this->revoke($sessionId, $actor, $actor->id, $object instanceof UserInterface ? $object->getRoles() : $actor->roles);
    }

    /**
     * Since 1.35.0: ends every client session of a user ("sign out everywhere": every app must sign in again) and returns
     * how many were ended. The host authorises the call, as for {@see self::revokeByCredential()}. One `DELETE` statement
     * (nothing is flushed, cleared or reset); a lock conflict propagates as `TransientStorageFailure` with nothing committed.
     *
     * @param string $userId The user id (as `TimeTrackUserRef::$id`), compared byte for byte
     *
     * @throws LogicException when the configured token store cannot delete a user's sessions
     * @throws TransientStorageFailure when the storage failed transiently: nothing was committed, retry
     */
    public function revokeAllForUser(string $userId): int
    {
        if (!$this->tokens instanceof UserClientTokenRepositoryInterface) {
            throw new LogicException('The TimeTrack token store does not implement ' . UserClientTokenRepositoryInterface::class . ': it cannot revoke every session of a user.');
        }
        // @igor-ignore - Request-scoped value or I/O delegate (repository call); no worker state.
        $count = $this->tokens->removeByUserId($userId);
        if ($count > 0) {
            $this->auditor?->recordForUserId(ClientApiAuditAction::SessionRevoked, $userId, null, null, null, 'all');
        }
        $this->logger->info('TimeTrack client sessions of a user revoked.', ['bundle' => 'time_track', 'action' => 'client_sessions_revoke_all', 'count' => $count]);

        return $count;
    }

    /**
     * @param array<string> $roles The actor's role names (no hierarchy)
     */
    private function revoke(ClientSessionId|string $sessionId, TimeTrackUserRef $actorRef, ?string $actorId, array $roles): ClientSessionRevocation
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
        $isOwner = $actorId !== null && $actorId === $session->ownerId;
        if (!$this->isGranted($actorRef, $roles, $session, $isOwner)) {
            $this->logger->info('TimeTrack client session revocation refused.', ['bundle' => 'time_track', 'action' => 'client_session_revoke', 'reason' => 'forbidden']);

            return ClientSessionRevocation::NotFound;
        }
        // @igor-ignore - Request-scoped value or I/O delegate (repository call); no worker state.
        if ($store->removeSessionById($session->id) === 0) {
            // A concurrent call revoked it first (or the purge removed it): the same answer as an unknown id.
            return ClientSessionRevocation::NotFound;
        }

        try {
            $this->auditor?->recordForUserId(ClientApiAuditAction::SessionRevoked, $session->ownerId, $session->credentialId, $session->clientType, null, $isOwner ? 'owner' : 'other', $session->scopeFamily);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack client session audit listener failed.', ['bundle' => 'time_track', 'action' => 'client_session_revoke', 'exception' => $exception::class]);
        }
        $this->logger->info('TimeTrack client session revoked.', ['bundle' => 'time_track', 'action' => 'client_session_revoke', 'client' => $session->clientType->value]);

        return ClientSessionRevocation::Revoked;
    }

    /**
     * Whether a reference's class names a user: unknown (`''`), `user_class`, an accepted class or a subclass of one; any
     * class when this revoker has no `user_class`.
     */
    private function isUserClass(string $class): bool
    {
        if ($class === '' || $this->userClass === '') {
            return true;
        }
        foreach ([$this->userClass, ...$this->acceptedUserClasses] as $accepted) {
            if ($class === $accepted || is_a($class, $accepted, true)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<string> $roles
     */
    private function isGranted(TimeTrackUserRef $actorRef, array $roles, ClientSessionRecord $session, bool $isOwner): bool
    {
        $granted = $isOwner || array_intersect($this->adminRoles, $roles) !== [];
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return $granted;
        }
        $event = new ClientSessionAccessCheckEvent($actorRef, $session->id, $session->ownerId, $session->credentialId, $session->clientType, ClientSessionAccessCheckEvent::ACTION_REVOKE, $granted);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK);

        return $event->isGranted();
    }
}
