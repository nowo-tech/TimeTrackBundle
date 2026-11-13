<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.25.0: the default {@see ServiceAccountPolicyInterface}: every account is a person (the behaviour before 1.25).
 * Stateless.
 */
final class NoServiceAccountPolicy implements ServiceAccountPolicyInterface
{
    /**
     * Always false.
     */
    public function isServiceAccount(TimeTrackUserRef $account): bool
    {
        return false;
    }
}
