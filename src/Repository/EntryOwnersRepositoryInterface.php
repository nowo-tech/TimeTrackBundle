<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13: the users and scopes that have entries in a time window (auto-lock creates timesheet rows only for
 * them, never for every user).
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
