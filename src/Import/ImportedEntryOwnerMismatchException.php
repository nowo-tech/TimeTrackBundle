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
     *
     * @param string|null $externalId Since 1.15: the row that stopped the batch, for the host; kept out of the message
     */
    public function __construct(public readonly ?string $externalId = null)
    {
        parent::__construct('An imported time entry cannot change its owner; remove it and import it again.');
    }
}
