<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicy;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicySourceInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use RuntimeException;

use function array_key_exists;

/**
 * Host monitoring policy a test sets: one policy for everyone, or per user id; `failWith` makes the port throw.
 */
final class StubMonitoringPolicySource implements MonitoringPolicySourceInterface
{
    public ?MonitoringPolicy $policy = null;

    /** @var array<int|string, MonitoringPolicy|null> */
    public array $byUser = [];

    public ?string $failWith = null;

    /** @var list<string|null> user ids asked */
    public array $calls = [];

    public function policyOf(TimeTrackUserRef $user): ?MonitoringPolicy
    {
        $this->calls[] = $user->id;
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        return array_key_exists((string) $user->id, $this->byUser) ? $this->byUser[(string) $user->id] : $this->policy;
    }
}
