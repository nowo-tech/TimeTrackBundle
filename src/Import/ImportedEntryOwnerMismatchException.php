<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use RuntimeException;

/**
 * Since 1.11: an upsert named another owner for an existing imported entry. The writer never moves an entry between
 * users: re-association is an explicit remove + upsert by the host (audited there). Nothing was written.
 */
final class ImportedEntryOwnerMismatchException extends RuntimeException
{
    /**
     * Creates the exception (the message names no user and no entry).
     */
    public function __construct()
    {
        parent::__construct('An imported time entry cannot change its owner; remove it and import it again.');
    }
}
