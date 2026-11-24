<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Event\MonitoringPersonDetailViewedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as ListenerAwareDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Since 1.41.0 (owner decision D9 of the 2026-11-24 monitoring study; public service): what third parties may see of a
 * worker's monitoring evidence in the host's team views.
 *
 * - {@see granularity()}: the **maximum** granularity a policy allows — `project_aggregates` (default: totals per
 *   project, never one person's slots, app shares or thumbnails) or `per_person` (only for an organisation policy whose
 *   purpose is `billing_evidence` and that recorded the opt-in date, see {@see MonitoringPolicy}). Use it to pick the
 *   level of a list page; {@see authorizePersonDetail()} is the authority for one person's detail.
 * - {@see authorizePersonDetail()}: call it before showing one person's detail to anyone but that person. It reads the
 *   worker's current policy and last acknowledgement and refuses ({@see MonitoringTeamDetailDeniedException}) unless
 *   per-person detail is allowed, the opt-in date has come, the worker acknowledged the policy after it, and a listener
 *   records the view. Otherwise it dispatches {@see MonitoringPersonDetailViewedEvent} (the audit trail) and returns a
 *   {@see MonitoringPersonDetailGrant} whose `visibleSince` bounds the evidence you may show (audit ZBB-M2).
 *
 * The bundle does not know your roles: check `view_team` and the project scope first; this guard only narrows. The
 * worker's own view (same user class **and** id, audit ZBB-M1) is always allowed and not audited. Stateless; the
 * policy and the acknowledgement are read per call (worker mode).
 */
final readonly class MonitoringTeamViewGuard
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * Receives the host policy source (null = monitoring not configured: every third-party detail is refused), the
     * acknowledgement store (null = refused: no proof the worker accepted), and the dispatcher of the audit event (null,
     * or no listener for it = refused: no detail without its record).
     */
    public function __construct(
        private ?MonitoringPolicySourceInterface $policySource = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        private ?MonitoringAcknowledgementStoreInterface $acknowledgements = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The maximum granularity third parties may get under the policy: {@see MonitoringPolicy::TEAM_VIEW_PER_PERSON} only
     * when {@see MonitoringPolicy::allowsPerPersonDetail()}, otherwise {@see MonitoringPolicy::TEAM_VIEW_PROJECT_AGGREGATES}.
     * It does not check the opt-in date or the acknowledgement: {@see authorizePersonDetail()} does.
     */
    public static function granularity(?MonitoringPolicy $policy): string
    {
        return $policy instanceof MonitoringPolicy && $policy->scope === 'organization' && $policy->allowsPerPersonDetail()
            ? MonitoringPolicy::TEAM_VIEW_PER_PERSON
            : MonitoringPolicy::TEAM_VIEW_PROJECT_AGGREGATES;
    }

    /**
     * Allows `$viewer` to see `$worker`'s per-person detail now, from the grant's `visibleSince` on, or throws.
     * Exceptions of the policy source, the acknowledgement store and the audit listeners propagate (the view must not
     * happen then).
     *
     * @throws MonitoringTeamDetailDeniedException when per-person detail is not allowed
     */
    public function authorizePersonDetail(TimeTrackUserRef $viewer, TimeTrackUserRef $worker): MonitoringPersonDetailGrant
    {
        if ($viewer->id === null || $worker->id === null) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_UNKNOWN_USER);
        }
        $policy = $this->policySource?->policyOf($worker);
        if ($viewer->userClass === $worker->userClass && $viewer->id === $worker->id) {
            // The worker's own data (same class and id, exact): always theirs to see, not a third-party view.
            return new MonitoringPersonDetailGrant($policy, null, true);
        }
        if (!$policy instanceof MonitoringPolicy) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_NO_POLICY);
        }
        if ($policy->scope !== 'organization') {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_PERSONAL);
        }
        if (self::granularity($policy) !== MonitoringPolicy::TEAM_VIEW_PER_PERSON) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_AGGREGATES_ONLY);
        }
        $now   = $this->clock->now();
        $optIn = new DateTimeImmutable($policy->perPersonDetailRecordedAt . ' 00:00:00', new DateTimeZone('UTC'));
        if ($optIn->getTimestamp() > $now->getTimestamp()) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_OPT_IN_PENDING);
        }
        $last = $this->acknowledgements?->lastAcknowledged($worker);
        // The worker accepted this policy (or a version it did not widen) after the organisation opted in.
        if (!$last instanceof MonitoringAcknowledgement || MonitoringContext::acknowledgementRequired($policy, $last) || $last->acknowledgedAt->getTimestamp() < $optIn->getTimestamp()) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_NOT_ACKNOWLEDGED);
        }
        if (!$this->eventDispatcher instanceof ListenerAwareDispatcherInterface || !$this->eventDispatcher->hasListeners(TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED)) {
            throw $this->denied(MonitoringTeamDetailDeniedException::REASON_NO_AUDIT);
        }
        $visibleSince = $last->acknowledgedAt->getTimestamp() > $optIn->getTimestamp() ? DateTimeImmutable::createFromInterface($last->acknowledgedAt) : $optIn;
        $this->eventDispatcher->dispatch(
            new MonitoringPersonDetailViewedEvent($viewer, $worker, $policy->policyVersion, MonitoringPolicy::PURPOSE_BILLING_EVIDENCE, DateTimeImmutable::createFromInterface($now), $visibleSince),
            TimeTrackEvents::MONITORING_PERSON_DETAIL_VIEWED,
        );
        $this->logger->info('TimeTrack monitoring person detail viewed.', ['bundle' => 'time_track', 'action' => 'monitoring_person_detail', 'purpose' => MonitoringPolicy::PURPOSE_BILLING_EVIDENCE]);

        return new MonitoringPersonDetailGrant($policy, $visibleSince, false);
    }

    private function denied(string $reason): MonitoringTeamDetailDeniedException
    {
        $this->logger->info('TimeTrack monitoring person detail refused.', ['bundle' => 'time_track', 'action' => 'monitoring_person_detail', 'reason' => $reason]);

        return new MonitoringTeamDetailDeniedException($reason);
    }
}
