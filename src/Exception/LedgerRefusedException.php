<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * Since 1.13: a balance ledger line was refused (`forbidden`, `reason_required`, `invalid_seconds`, `invalid_date`,
 * `opening_exists`, `not_found`, `already_reversed`, `not_reversible`, `ratify_only`). The message carries no personal
 * data.
 */
final class LedgerRefusedException extends RuntimeException
{
    /**
     * Creates the exception for a stable code.
     */
    public function __construct(private readonly string $errorCode)
    {
        parent::__construct('Balance ledger line refused: ' . $errorCode . '.');
    }

    /**
     * Stable error code.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
