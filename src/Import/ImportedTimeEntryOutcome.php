<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

/**
 * Since 1.11: what an upsert did.
 */
enum ImportedTimeEntryOutcome: string
{
    case Created   = 'created';
    case Updated   = 'updated';
    case Unchanged = 'unchanged';
}
