<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see EntryOwnersRepositoryInterface} (REQ-ARCH-001 rule 3), an optional
 * capability of the time entry store (auto-lock). Each reference must carry the user object
 * ({@see TimeTrackUserRef::of()}): the timesheet service stores it.
 */
interface EntryOwnerRefsRepositoryInterface
{
    /**
     * Distinct (owner, scope) pairs of the entries started in [$from, $to] that have a scope.
     *
     * @return list<array{user: TimeTrackUserRef, scopeKey: string}>
     */
    public function ownerRefsWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
