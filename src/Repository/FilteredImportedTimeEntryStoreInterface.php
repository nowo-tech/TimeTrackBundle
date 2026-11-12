<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryRemoval;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryScope;

/**
 * Since 1.23.0: framework-free twin of {@see FilteredImportedTimeEntryRepositoryInterface} (REQ-ARCH-001 rule 3): bulk
 * removal of imported entries that keeps the rows a filter protects (the period lock). Required for bulk removals while
 * timesheets are on.
 */
interface FilteredImportedTimeEntryStoreInterface extends ImportedTimeEntryStoreInterface
{
    /**
     * Like {@see ImportedTimeEntryStoreInterface::removeImported()}, but a row for which `$keep` returns true is not
     * deleted (nor counted). `userId` is null for an ownerless entry, and the row has its `personRef`.
     *
     * @param callable(array{id: string, userId: string|null, personRef: string|null, scopeKey: string|null, startedAt: DateTimeImmutable}): bool $keep
     */
    public function removeImportedWhere(ImportedTimeEntryScope $scope, callable $keep, int $batchSize = 500): ImportedTimeEntryRemoval;
}
