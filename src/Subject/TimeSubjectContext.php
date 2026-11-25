<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 2.1.0 (Beacon spec 256): who asks a {@see TimeSubjectProviderInterface}, and in which tenant.
 *
 * - `actor`: the owner of the time on writes (also when a host writes for another person through
 *   `TimeEntryOperationsInterface`, Q-TS-7), the viewer on reads. Its user object stays hidden in the reference.
 * - `scopeKey`: the entry's tenant scope, `''` when the host has no scope provider (the convention of
 *   `TimeEntryBillableDefaultProviderInterface::defaultFor()`). A subject is always checked in this scope: an entry never
 *   has a subject from another tenant.
 *
 * Framework-free, immutable, built per call.
 */
final readonly class TimeSubjectContext
{
    /**
     * @param TimeTrackUserRef $actor The person whose time it is (writes) or who reads it (reads)
     * @param string $scopeKey The entry's tenant scope ('' = no scope)
     */
    public function __construct(public TimeTrackUserRef $actor, public string $scopeKey)
    {
    }
}
