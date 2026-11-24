<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use RuntimeException;

/**
 * Since 1.41.0 (D9): {@see MonitoringTeamViewGuard::authorizePersonDetail()} refused a per-person view. Show project
 * aggregates instead. `reason` is one of the `REASON_*` constants; the message never names a person.
 */
final class MonitoringTeamDetailDeniedException extends RuntimeException
{
    /** No monitoring policy applies to the worker (or monitoring is not configured). */
    public const REASON_NO_POLICY = 'no_policy';

    /** The worker's policy is personal self-analytics: only the worker sees it. */
    public const REASON_PERSONAL = 'personal';

    /** The policy shows project aggregates only (no billing-evidence opt-in, or not collecting). */
    public const REASON_AGGREGATES_ONLY = 'aggregates_only';

    /** Since audit ZBB-M2: the organisation's opt-in date (`perPersonDetailRecordedAt`) is in the future. */
    public const REASON_OPT_IN_PENDING = 'opt_in_pending';

    /** Since audit ZBB-M2: the worker has not acknowledged the per-person policy (after the opt-in date). */
    public const REASON_NOT_ACKNOWLEDGED = 'not_acknowledged';

    /** Since audit ZBB-L1: nothing would record the view (no dispatcher, or no listener for the view event). */
    public const REASON_NO_AUDIT = 'no_audit';

    /** The viewer or the worker has no id. */
    public const REASON_UNKNOWN_USER = 'unknown_user';

    /**
     * @param string $reason One of the `REASON_*` constants
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Per-person monitoring detail is not allowed here (' . $reason . '); show project aggregates.');
    }
}
