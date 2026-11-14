<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use RuntimeException;

/**
 * Since 1.26.0 (audit PM-L6, Beacon Q2): thrown by a {@see MonitoringEvidenceStoreInterface} store method when the host
 * refuses the evidence for good (an image it cannot decode, an item it will never accept). The client gets
 * `422 evidence_rejected` and must not retry; the upload intent is burnt. Any other exception of the port is a
 * temporary failure (`503 monitoring_unavailable`, retried). The message is never sent to the client nor logged.
 */
final class MonitoringEvidenceRejectedException extends RuntimeException
{
}
