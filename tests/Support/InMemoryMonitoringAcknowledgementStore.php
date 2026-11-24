<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgementStoreInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.41.0: one acknowledgement for every user (unit tests of the team-view guard).
 */
final class InMemoryMonitoringAcknowledgementStore implements MonitoringAcknowledgementStoreInterface
{
    public ?MonitoringAcknowledgement $last = null;

    public function acknowledge(TimeTrackUserRef $user, MonitoringAcknowledgement $acknowledgement): void
    {
        $this->last = $acknowledgement;
    }

    public function lastAcknowledged(TimeTrackUserRef $user): ?MonitoringAcknowledgement
    {
        return $this->last;
    }
}
