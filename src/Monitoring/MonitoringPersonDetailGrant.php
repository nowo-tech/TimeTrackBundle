<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;

/**
 * Since 1.41.0 (owner decision D9, audit ZBB-M2): what {@see MonitoringTeamViewGuard::authorizePersonDetail()} allows.
 *
 * A third-party view may show only evidence **collected at or after `visibleSince`**: the later of the organisation's
 * opt-in date (`perPersonDetailRecordedAt`, 00:00 UTC) and the worker's acknowledgement of the policy that shows
 * per-person detail. Evidence collected before (for example under `project_costing`) stays in the project aggregates
 * (purpose limitation, GDPR art. 5.1.b). Filter your evidence query with {@see self::covers()} or with
 * `collected_at >= visibleSince`. The worker's own view has no lower bound (`visibleSince` null). Framework-free.
 */
final readonly class MonitoringPersonDetailGrant
{
    /**
     * @param MonitoringPolicy|null $policy The worker's current policy (null only for the worker's own view without a policy)
     * @param DateTimeImmutable|null $visibleSince Lower bound of the evidence that may be shown (null = the worker's own view, no bound)
     * @param bool $ownView The viewer is the worker (same user class and id)
     */
    public function __construct(
        public ?MonitoringPolicy $policy,
        public ?DateTimeImmutable $visibleSince,
        public bool $ownView,
    ) {
    }

    /**
     * Whether evidence collected at `$collectedAt` (a screenshot's `takenAt`, an activity slot's start) may be shown.
     */
    public function covers(DateTimeImmutable $collectedAt): bool
    {
        return !$this->visibleSince instanceof DateTimeImmutable || $collectedAt->getTimestamp() >= $this->visibleSince->getTimestamp();
    }
}
