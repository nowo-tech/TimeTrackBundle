<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Event\MonitoringPersonDetailViewedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Since 1.41.0 (owner decision D9 of the 2026-11-24 monitoring study; public service): what third parties may see of a
 * worker's monitoring evidence in the host's team views.
 *
 * - {@see granularity()}: `project_aggregates` (default — totals per project, never one person's slots, app shares or
 *   thumbnails) or `per_person` (only for an organisation policy whose purpose is `billing_evidence` and that recorded
 *   the opt-in date, see {@see MonitoringPolicy}).
 * - {@see authorizePersonDetail()}: call it before showing one person's detail to anyone but that person. It reads the
 *   worker's current policy, refuses ({@see MonitoringTeamDetailDeniedException}) unless per-person detail is allowed,
 *   and otherwise dispatches {@see MonitoringPersonDetailViewedEvent} (the audit trail) before returning.
 *
 * The bundle does not know your roles: check `view_team` and the project scope first; this guard only narrows. The
 * worker's own view is always allowed and not audited. Stateless; the policy is read per call (worker mode).
 */
final readonly class MonitoringTeamViewGuard
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * Receives the host policy source (null = monitoring not configured: every third-party detail is refused) and the
     * dispatcher of the audit event (null = refused too: no detail without its record).
     */
    public function __construct(
        private ?MonitoringPolicySourceInterface $policySource = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * What third parties may see under the policy: {@see MonitoringPolicy::TEAM_VIEW_PER_PERSON} only when
     * {@see MonitoringPolicy::allowsPerPersonDetail()}, otherwise {@see MonitoringPolicy::TEAM_VIEW_PROJECT_AGGREGATES}.
     */
    public static function granularity(?MonitoringPolicy $policy): string
    {
        return $policy instanceof MonitoringPolicy && $policy->scope === 'organization' && $policy->allowsPerPersonDetail()
            ? MonitoringPolicy::TEAM_VIEW_PER_PERSON
            : MonitoringPolicy::TEAM_VIEW_PROJECT_AGGREGATES;
    }

    /**
     * Allows `$viewer` to see `$worker`'s per-person detail now, or throws. Exceptions of the policy source and of the
     * audit listeners propagate (the view must not happen then).
     *
     * @throws MonitoringTeamDetailDeniedException when per-person detail is not allowed
     *
     * @return MonitoringPolicy|null the worker's policy (null only for the worker's own view without a policy)
     */
    public function authorizePersonDetail(TimeTrackUserRef $viewer, TimeTrackUserRef $worker): ?MonitoringPolicy
    {
        if ($viewer->id === null || $worker->id === null) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_UNKNOWN_USER);
        }
        $policy = $this->policySource?->policyOf($worker);
        if ($viewer->id === $worker->id) {
            // The worker's own data: always theirs to see, not a third-party view.
            return $policy;
        }
        if (!$policy instanceof MonitoringPolicy) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_NO_POLICY);
        }
        if ($policy->scope !== 'organization') {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_PERSONAL);
        }
        if (self::granularity($policy) !== MonitoringPolicy::TEAM_VIEW_PER_PERSON || !$this->eventDispatcher instanceof EventDispatcherInterface) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_AGGREGATES_ONLY);
        }
        $this->eventDispatcher->dispatch(
            new MonitoringPersonDetailViewedEvent($viewer, $worker, $policy->policyVersion, MonitoringPolicy::PURPOSE_BILLING_EVIDENCE, DateTimeImmutable::createFromInterface($this->clock->now())),
            TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED,
        );
        $this->logger->info('TimeTrack monitoring person detail viewed.', ['bundle' => 'time_track', 'action' => 'monitoring_person_detail', 'purpose' => MonitoringPolicy::PURPOSE_BILLING_EVIDENCE]);

        return $policy;
    }

    private function denied(string $reason): MonitoringTeamDetailDeniedException
    {
        $this->logger->info('TimeTrack monitoring person detail refused.', ['bundle' => 'time_track', 'action' => 'monitoring_person_detail', 'reason' => $reason]);

        return new MonitoringTeamDetailDeniedException($reason);
    }
}
