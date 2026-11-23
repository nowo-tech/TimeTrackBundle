<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use Nowo\TimeTrackBundle\Repository\FilteredImportedTimeEntryStoreInterface;

/**
 * Since 1.13: bulk removal of imported entries that keeps the rows a filter protects (the period lock: an entry of a
 * locked or submitted period is never deleted by a bundle path). Required for bulk removals while timesheets are on.
 * Since 1.23.0 `removeImportedWhere()` lives in {@see FilteredImportedTimeEntryStoreInterface}.
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Repository\FilteredImportedTimeEntryRepositoryInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see FilteredImportedTimeEntryStoreInterface} (a gate adapts it).
 */
interface FilteredImportedTimeEntryRepositoryInterface extends ImportedTimeEntryRepositoryInterface, FilteredImportedTimeEntryStoreInterface
{
}
