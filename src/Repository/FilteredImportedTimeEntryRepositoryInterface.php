<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;

/**
 * Since 1.13: bulk removal of imported entries that keeps the rows a filter protects (the period lock: an entry of a
 * locked or submitted period is never deleted by a bundle path). Required for bulk removals while timesheets are on.
 */
interface FilteredImportedTimeEntryRepositoryInterface extends ImportedTimeEntryRepositoryInterface
{
    /**
     * Like {@see ImportedTimeEntryRepositoryInterface::removeImported()}, but a row for which `$keep` returns true is
     * not deleted (nor counted).
     *
     * @param callable(array{id: string, userId: string, scopeKey: string|null, startedAt: DateTimeImmutable}): bool $keep
     */
    public function removeImportedWhere(ImportedTimeEntryScope $scope, callable $keep, int $batchSize = 500): ImportedTimeEntryRemoval;
}
