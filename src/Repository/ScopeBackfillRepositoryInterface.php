<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.13: keyset access to entries without a tenant scope, for `nowo:time-track:entries:backfill-scope`.
 */
interface ScopeBackfillRepositoryInterface
{
    /**
     * Up to `$limit` entries with a null `scope_key` and an id after `$afterId`, by id.
     *
     * @return list<TimeEntry>
     */
    public function findScopelessAfter(string $afterId, int $limit): array;

    /**
     * Sets the scope of entries that still have none (entry id => scope key); returns the rows changed. The loaded
     * entries are detached afterwards (the command walks large tables).
     *
     * @param array<string, string> $scopeKeys
     */
    public function assignScopeKeys(array $scopeKeys): int;
}
