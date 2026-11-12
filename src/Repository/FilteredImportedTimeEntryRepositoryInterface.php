<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

/**
 * Since 1.13: bulk removal of imported entries that keeps the rows a filter protects (the period lock: an entry of a
 * locked or submitted period is never deleted by a bundle path). Required for bulk removals while timesheets are on.
 * Since 1.23.0 `removeImportedWhere()` lives in {@see FilteredImportedTimeEntryStoreInterface}.
 *
 * @deprecated since 1.23.0, implement {@see FilteredImportedTimeEntryStoreInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface FilteredImportedTimeEntryRepositoryInterface extends ImportedTimeEntryRepositoryInterface, FilteredImportedTimeEntryStoreInterface
{
}
