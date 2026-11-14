<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use Nowo\TimeTrackBundle\Repository\DoctrineOrmMonitoringAcknowledgementStore;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.26.0 (contract §24.2 / §24.6): where the workers' acknowledgements of the monitoring notice are kept.
 * Framework-free (REQ-ARCH-001 rule 3).
 *
 * Standard default: {@see DoctrineOrmMonitoringAcknowledgementStore} (a bundle table, written through the bundle's
 * entity manager). DDD hosts alias this interface (or set `client_api.monitoring.acknowledgement_store`) to their own
 * store; the host's web page that acknowledges must write to the same store so the desktop does not ask twice.
 */
interface MonitoringAcknowledgementStoreInterface
{
    /**
     * Records the acknowledgement. Acknowledging a version the worker already acknowledged keeps the first record.
     */
    public function acknowledge(TimeTrackUserRef $user, MonitoringAcknowledgement $acknowledgement): void;

    /**
     * The worker's acknowledgement of the highest policy version, or null when they never acknowledged one.
     */
    public function lastAcknowledged(TimeTrackUserRef $user): ?MonitoringAcknowledgement;
}
