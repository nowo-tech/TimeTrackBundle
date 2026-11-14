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
     */
    public function record(ClientApiAuditAction $action, ?object $user, ?string $credentialId, ?ClientType $clientType, ?string $clientIp, ?string $reason = null): void
    {
        $event = new ClientApiEvent(
            $action,
            $user !== null ? $this->userId($user) : null,
            $credentialId,
            $clientType?->value,
            IpPrefix::of($clientIp),
            $reason,
            $this->clock->now(),
        );

        $this->eventDispatcher?->dispatch($event, TimeTrackEvents::CLIENT_API);
        $this->logger->info('TimeTrack client API audit.', ['bundle' => 'time_track', 'action' => 'client_api_' . $action->value, 'client' => $clientType?->value, 'reason' => $reason]);
    }

    /**
     * Records a refused request (401 / 403 / 429), throttled per credential (or user, or IP prefix) and reason.
     *
     * @param ClientToken|null $token Resolved token, when the refusal came after token resolution
     */
    public function denied(string $reason, ?ClientToken $token, ?string $clientIp): void
    {
        $key = $token instanceof ClientToken
            ? 'token:' . ($token->getCredentialId() ?? 'user:' . $this->userId($token->getUser()))
            : 'ip:' . (IpPrefix::of($clientIp) ?? 'none');

        if ($this->rateLimiter instanceof ClientApiRateLimiter && $this->rateLimiter->hit('audit', $key . '|' . $reason, 1, $this->denialIntervalSeconds) > 0) {
            return;
        }

        $this->record(
            ClientApiAuditAction::RequestDenied,
            $token?->getUser(),
            $token?->getCredentialId(),
            $token?->getClientType(),
            $clientIp,
            $reason,
        );
    }

    /**
     * Since 1.26: records a refused monitoring request (`kind_off`, `not_acknowledged`, `outside_timer`,
     * `outside_hours`, `too_old`, `duplicate`, `policy_changed`, `notice_mismatch`, `invalid_signature`, a cap code),
     * throttled per credential (or user) and reason like {@see self::denied()}. Never an image, an app or a window name.
     */
    public function monitoringRefused(string $reason, ClientToken $token, ?string $clientIp): void
    {
        $key = 'token:' . ($token->getCredentialId() ?? 'user:' . $this->userId($token->getUser()));
        if ($this->rateLimiter instanceof ClientApiRateLimiter && $this->rateLimiter->hit('audit_monitoring', $key . '|' . $reason, 1, $this->denialIntervalSeconds) > 0) {
            return;
        }

        $this->record(ClientApiAuditAction::MonitoringRefused, $token->getUser(), $token->getCredentialId(), $token->getClientType(), $clientIp, $reason);
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
