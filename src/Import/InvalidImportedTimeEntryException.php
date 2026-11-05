<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use InvalidArgumentException;

use function sprintf;

/**
 * Since 1.11: imported data the writer refuses. The message names the field and the rule, never the value.
 */
final class InvalidImportedTimeEntryException extends InvalidArgumentException
{
    /**
     * Creates the object.
     */
    public function __construct(
        public readonly string $field,
        public readonly string $rule,
    ) {
        parent::__construct(sprintf('Imported time entry refused: %s (%s).', $field, $rule));
    }
}
