<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.34.0 ({@see TimeTrackEvents::CLIENT_SESSION_ACCESS_CHECK}): whether the actor may act on one client session
 * (action `revoke`, {@see ClientTokenRevoker::revokeById()}). Dispatched after the bundle decided — granted for the
 * session owner and for a user with one of `security.admin_roles` — and only for a session that exists. A listener may
 * grant (e.g. a support role of the owner's organisation) or deny (e.g. an admin of another tenant). Framework-free
 * (REQ-ARCH-001 rule 3): the actor is a {@see TimeTrackUserRef}.
 */
final class ClientSessionAccessCheckEvent extends Event
{
    /** The action of {@see ClientTokenRevoker::revokeById()}. */
    public const ACTION_REVOKE = 'revoke';

    /**
     * @param TimeTrackUserRef $actorRef The user asking
     * @param string $sessionId Session id
     * @param string $ownerId Id of the session owner
     * @param string|null $credentialId Host credential the session was issued from
     * @param ClientType $clientType Client that holds the session
     * @param string $action Requested action
     * @param bool $granted The bundle's decision
     */
    public function __construct(
        private readonly TimeTrackUserRef $actorRef,
        private readonly string $sessionId,
        private readonly string $ownerId,
        private readonly ?string $credentialId,
        private readonly ClientType $clientType,
        private readonly string $action,
        private bool $granted,
    ) {
    }

    /**
     * The user asking.
     */
    public function actorRef(): TimeTrackUserRef
    {
        return $this->actorRef;
    }

    /**
     * Session id.
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Id of the session owner.
     */
    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    /**
     * Host credential the session was issued from (null: none recorded).
     */
    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    /**
     * Client that holds the session.
     */
    public function getClientType(): ClientType
    {
        return $this->clientType;
    }

    /**
     * Requested action ({@see self::ACTION_REVOKE}).
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Current decision.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }

    /**
     * Grants the action.
     */
    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
    }

    /**
     * Denies the action.
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = false;
    }
}
