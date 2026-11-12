<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/** Twin of {@see StubTimesheetApproverProvider} (same state). */
final class TwinApproverPolicy implements TimesheetApproverPolicyInterface
{
    public int $calls = 0;

    public function __construct(private readonly StubTimesheetApproverProvider $inner)
    {
    }

    public function mayApprove(TimeTrackUserRef $approver, string $ownerId, string $scopeKey): bool
    {
        ++$this->calls;

        return $this->inner->canApprove(TwinPorts::user($approver), $ownerId, $scopeKey);
    }

    public function reviewableUserIdsFor(TimeTrackUserRef $approver, string $scopeKey): array
    {
        ++$this->calls;

        return $this->inner->reviewableUserIds(TwinPorts::user($approver), $scopeKey);
    }

    public function approverIdsFor(string $ownerId, string $scopeKey): array
    {
        ++$this->calls;

        return $this->inner->approverIdsFor($ownerId, $scopeKey);
    }
}
