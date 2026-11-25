<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port\Internal;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Repository\EntryOwnerRefsRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13: the users and scopes that have entries in a time window (auto-lock creates timesheet rows only for
 * them, never for every user).
 *
 * @internal since 2.0 (REQ-ARCH-001 rule 3): the shape the bundle asks this port through; the 1.x public interface
 * `Nowo\TimeTrackBundle\Repository\EntryOwnersRepositoryInterface` (deprecated since 1.23.0) is removed in 2.0. Hosts implement {@see EntryOwnerRefsRepositoryInterface} (a gate adapts it).
 */
interface EntryOwnersRepositoryInterface
{
    /**
     * Distinct (owner, scope) pairs of the entries started in [$from, $to] that have a scope.
     *
     * @return list<array{user: UserInterface, scopeKey: string}>
     */
    public function ownersWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
