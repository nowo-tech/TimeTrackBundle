<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;

use const DATE_ATOM;

/**
 * Audit event of the client API (`nowo_time_track.client_api`, contract v0.2 §4/§6): token issued / revoked, credential
 * revoked, device approved / denied and refused requests (rate limited per credential). Carries no token, no
 * credential secret, no note and no full IP: only the /24 (IPv4) or /64 (IPv6) prefix. Since 2.2.0 it also names the
 * scope family (`time`, or a host family such as `chat`) of the token or request when one is known, so a host audit
 * store can tell the families of one device apart.
 */
final readonly class ClientApiEvent
{
    /**
     * @param ClientApiAuditAction $action What happened
     * @param string|null $userId User id (null when unknown, e.g. an unknown token)
     * @param string|null $credentialId Host credential id
     * @param string|null $clientType `extension`, `desktop` or `web`
     * @param string|null $ipPrefix Client IP truncated to /24 or /64
     * @param string|null $reason Denial reason (`invalid_token`, `credential_inactive`, `insufficient_scope`, `task_forbidden`, `rate_limited`); since 1.26 the monitoring refusal code or `v<version>` of an acknowledgement
     * @param DateTimeImmutable $occurredAt When it happened
     * @param string|null $scopeFamily Since 2.2.0: scope family of the token or device request (null when unknown, e.g. an unknown token on the time API)
     */
    public function __construct(
        public ClientApiAuditAction $action,
        public ?string $userId,
        public ?string $credentialId,
        public ?string $clientType,
        public ?string $ipPrefix,
        public ?string $reason,
        public DateTimeImmutable $occurredAt,
        public ?string $scopeFamily = null,
    ) {
    }

    /**
     * Event payload as an array (for host audit stores).
     *
     * @return array{action: string, userId: string|null, credentialId: string|null, clientType: string|null, ipPrefix: string|null, reason: string|null, occurredAt: string, scopeFamily: string|null}
     */
    public function toArray(): array
    {
        return [
            'action'       => $this->action->value,
            'userId'       => $this->userId,
            'credentialId' => $this->credentialId,
            'clientType'   => $this->clientType,
            'ipPrefix'     => $this->ipPrefix,
            'reason'       => $this->reason,
            'occurredAt'   => $this->occurredAt->format(DATE_ATOM),
            'scopeFamily'  => $this->scopeFamily,
        ];
    }
}
