<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Integration\ServiceAccountPolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

use function in_array;
use function str_starts_with;

/**
 * Host policy of the tests: an account is a service account when its identifier starts with `agent-` or its id is
 * listed in {@see self::$agentIds}. Records the ids it was asked about.
 */
final class StubServiceAccountPolicy implements ServiceAccountPolicyInterface
{
    /** @var list<string|null> */
    public array $asked = [];

    /**
     * @param list<string> $agentIds
     */
    public function __construct(public array $agentIds = [])
    {
    }

    public function isServiceAccount(TimeTrackUserRef $account): bool
    {
        $this->asked[] = $account->id;

        return str_starts_with($account->identifier, 'agent-') || in_array($account->id, $this->agentIds, true);
    }
}
