<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Monitoring\MonitoringTeamViewGuard;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.41.0 (owner decision D9, `nowo_time_track.monitoring.person_detail_viewed`): someone other than the worker is
 * about to see the worker's per-person monitoring detail under a policy whose purpose is billing evidence
 * ({@see MonitoringTeamViewGuard::authorizePersonDetail()}).
 *
 * The audit trail of the view: record it (who, whose, when, policy version) and show it on the worker's timeline.
 * Dispatched **before** anything is shown, synchronously: a listener that throws stops the view (the guard lets the
 * exception through), so the detail is never shown without its record. Framework-free; carries ids and the policy
 * version only, never evidence.
 */
final readonly class MonitoringPersonDetailViewedEvent
{
    /**
     * @param TimeTrackUserRef $viewer Who views (a `view_team` holder the host already authorised)
     * @param TimeTrackUserRef $worker Whose detail is shown
     * @param int $policyVersion The worker's policy version that allowed it
     * @param string $purpose Always `billing_evidence` in 1.41
     */
    public function __construct(
        public TimeTrackUserRef $viewer,
        public TimeTrackUserRef $worker,
        public int $policyVersion,
        public string $purpose,
        public DateTimeImmutable $viewedAt,
    ) {
    }
}
