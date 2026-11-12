<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use RuntimeException;

/**
 * Since 1.21: a change of an entry's `billable` flag was refused. The message is the stable code: `not_found` (no such
 * entry, or not one the caller may see), `read_only` (an imported entry), `anonymised` (an anonymised or ownerless
 * entry), `forbidden` (the edit grant of {@see TeamAccessGuard::isEditGranted()} said no).
 * Period locks are {@see PeriodWriteRefusedException} (409).
 */
final class BillableChangeRefusedException extends RuntimeException
{
    public const NOT_FOUND  = 'not_found';
    public const READ_ONLY  = 'read_only';
    public const ANONYMISED = 'anonymised';
    public const FORBIDDEN  = 'forbidden';

    /**
     * @param string $errorCode One of the class constants
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    /**
     * HTTP status of the client API answer: 404 (not found), 409 (read-only or anonymised entry) or 403 (forbidden).
     */
    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_FOUND => 404,
            self::FORBIDDEN => 403,
            default         => 409,
        };
    }
}
