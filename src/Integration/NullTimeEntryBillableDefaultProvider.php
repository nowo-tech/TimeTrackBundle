<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

/**
 * Since 1.21: the default {@see TimeEntryBillableDefaultProviderInterface}: no default, new entries keep `billable: null`
 * (the behaviour before 1.21).
 */
final class NullTimeEntryBillableDefaultProvider implements TimeEntryBillableDefaultProviderInterface
{
    /**
     * Always null.
     */
    public function defaultFor(string $scopeKey, ?string $taskRef): ?bool
    {
        return null;
    }
}
