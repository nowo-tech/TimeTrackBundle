<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Support\IpPrefix;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Audit trail of the client API: dispatches {@see ClientApiEvent} (`nowo_time_track.client_api`) and writes a PSR-3
 * record with the action, the client type and the reason only (REQ-OBS-001: no user, no IP, no token in logs).
 * Refused requests are throttled to one event per credential (or user, or IP prefix) and reason per
 * `client_api.audit.denial_interval_seconds`, so a misbehaving client cannot flood the host audit store.
 *
 * Since 2.2.0 every record carries the scope family of the token or device request (`scopeFamily` of the event and the
 * `family` log field), and the denial throttle is kept per family.
 */
final readonly class ClientApiAuditor
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches the audit event (null = log only)
     * @param ClientApiRateLimiter|null $rateLimiter Throttles the denial events (null = no throttle)
     * @param int $denialIntervalSeconds One denial event per key and reason per interval
     */
    public function __construct(
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ClientApiRateLimiter $rateLimiter = null,
        private int $denialIntervalSeconds = 60,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Records an action.
     *
     * @param object|null $user Acting user (its id is resolved; null when unknown)
     * @param string|null $clientIp Full client IP (only its /24 or /64 prefix leaves this method)
     * @param string|null $scopeFamily Since 2.2.0: scope family of the token or device request (null when unknown)
     */
    public function record(ClientApiAuditAction $action, ?object $user, ?string $credentialId, ?ClientType $clientType, ?string $clientIp, ?string $reason = null, ?string $scopeFamily = null): void
    {
        $this->recordForUserId($action, $user !== null ? $this->userId($user) : null, $credentialId, $clientType, $clientIp, $reason, $scopeFamily);
    }

    /**
     * Since 1.34.0: {@see self::record()} with the user's id instead of the user object (a row read as scalars).
     *
     * @param string|null $userId Id of the user the action concerns (null when unknown)
     * @param string|null $clientIp Full client IP (only its /24 or /64 prefix leaves this method)
     * @param string|null $scopeFamily Since 2.2.0: scope family of the token or device request (null when unknown)
     */
    public function recordForUserId(ClientApiAuditAction $action, ?string $userId, ?string $credentialId, ?ClientType $clientType, ?string $clientIp, ?string $reason = null, ?string $scopeFamily = null): void
    {
        $event = new ClientApiEvent(
            $action,
            $userId,
            $credentialId,
            $clientType?->value,
            IpPrefix::of($clientIp),
            $reason,
            $this->clock->now(),
            $scopeFamily,
        );

        $this->eventDispatcher?->dispatch($event, TimeTrackEvents::CLIENT_API);
        $this->logger->info('TimeTrack client API audit.', ['bundle' => 'time_track', 'action' => 'client_api_' . $action->value, 'client' => $clientType?->value, 'reason' => $reason, 'family' => $scopeFamily]);
    }

    /**
     * Records a refused request (401 / 403 / 429), throttled per credential (or user, or IP prefix) and reason.
     *
     * @param ClientToken|null $token Resolved token, when the refusal came after token resolution
     * @param string|null $scopeFamily Since 2.2.0: the family of the API that refused (null = the token's family, or unknown); the event names the token's family when there is a token
     */
    public function denied(string $reason, ?ClientToken $token, ?string $clientIp, ?string $scopeFamily = null): void
    {
        $family = $token?->getScopeFamily() ?? $scopeFamily;
        $key    = $token instanceof ClientToken
            ? 'token:' . ($token->getCredentialId() ?? 'user:' . $this->userId($token->getUser()))
            : 'ip:' . (IpPrefix::of($clientIp) ?? 'none');

        if ($this->rateLimiter instanceof ClientApiRateLimiter && $this->rateLimiter->hit('audit', $key . '|' . $reason . '|' . ($family ?? ''), 1, $this->denialIntervalSeconds) > 0) {
            return;
        }

        $this->record(
            ClientApiAuditAction::RequestDenied,
            $token?->getUser(),
            $token?->getCredentialId(),
            $token?->getClientType(),
            $clientIp,
            $reason,
            $family,
        );
    }

    /**
     * Since 1.26: records a refused monitoring request (`kind_off`, `not_acknowledged`, `outside_timer`,
     * `outside_hours`, `on_break` and `workday_unknown` (1.41), `too_old`, `duplicate`, `policy_changed`, `notice_mismatch`, `invalid_signature`, a cap code),
     * throttled per credential (or user) and reason like {@see self::denied()}. Never an image, an app or a window name.
     */
    public function monitoringRefused(string $reason, ClientToken $token, ?string $clientIp): void
    {
        $key = 'token:' . ($token->getCredentialId() ?? 'user:' . $this->userId($token->getUser()));
        if ($this->rateLimiter instanceof ClientApiRateLimiter && $this->rateLimiter->hit('audit_monitoring', $key . '|' . $reason, 1, $this->denialIntervalSeconds) > 0) {
            return;
        }

        $this->record(ClientApiAuditAction::MonitoringRefused, $token->getUser(), $token->getCredentialId(), $token->getClientType(), $clientIp, $reason, $token->getScopeFamily());
    }

    private function userId(object $user): ?string
    {
        if (!$user instanceof UserInterface) {
            return null;
        }

        try {
            return UserIdResolver::getId($user);
        } catch (Throwable) {
            return null;
        }
    }
}
