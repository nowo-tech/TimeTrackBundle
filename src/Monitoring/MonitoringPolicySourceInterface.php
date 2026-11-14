<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.26.0 (contract §24.6, spec 199; host, `client_api.monitoring.policy_source`): the activity-monitoring policy
 * of a worker. Framework-free (REQ-ARCH-001 rule 3). Without this port monitoring is off: `GET me` has no `monitoring`
 * block and every monitoring route answers 404.
 *
 * Called on `GET me` and on every monitoring request; keep it cheap and side-effect free. Nothing is memoised across
 * requests by the bundle (FrankenPHP worker mode, kernel reset false).
 */
interface MonitoringPolicySourceInterface
{
    /**
     * The policy that applies to the worker, or null when monitoring is off for them (nothing is collected).
     */
    public function policyOf(TimeTrackUserRef $user): ?MonitoringPolicy;
}
