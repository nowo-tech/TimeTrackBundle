<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use RuntimeException;

/**
 * Since 2.1.0 (Beacon spec 256): a subject or activity type on a write was refused. The message is the stable code:
 *
 * - `subject_forbidden` (403): unknown subject type, unknown id, or the owner may not log on it: one answer (no oracle);
 * - `allocation_conflict` (422): a subject next to a task whose type does not allow tasks
 *   (`TimeSubjectProviderInterface::allowsTask()` false);
 * - `activity_type_unknown` (422): no activity type provider, or the key is not one of the entry's tenant;
 * - `activity_type_inactive` (422): a retired activity type on a new write or a correction that sets it;
 * - `activity_type_required` (422): `entries.require_activity_type` and none given;
 * - `allocation_required` (409): `entries.require_allocation` and neither a task nor a subject (see
 *   {@see TimeAllocationRequiredException} for the timer stop), or a change that would leave the time unallocated.
 */
class TimeAllocationException extends RuntimeException
{
    public const SUBJECT_FORBIDDEN      = 'subject_forbidden';
    public const ALLOCATION_CONFLICT    = 'allocation_conflict';
    public const ACTIVITY_TYPE_UNKNOWN  = 'activity_type_unknown';
    public const ACTIVITY_TYPE_INACTIVE = 'activity_type_inactive';
    public const ACTIVITY_TYPE_REQUIRED = 'activity_type_required';
    public const ALLOCATION_REQUIRED    = 'allocation_required';

    /**
     * @param string $errorCode One of the class constants
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    /**
     * The HTTP status a host answers: 403 (subject), 409 (allocation required) or 422 (the values).
     */
    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::SUBJECT_FORBIDDEN   => 403,
            self::ALLOCATION_REQUIRED => 409,
            default                   => 422,
        };
    }
}
