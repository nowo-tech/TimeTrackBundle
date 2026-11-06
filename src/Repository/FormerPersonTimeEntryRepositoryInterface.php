<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;

/**
 * Since 1.15: optional store capability for entries with no user. Every write is a bulk statement with no flush, so it
 * is safe inside a host's erasure transaction. The Doctrine store implements it.
 */
interface FormerPersonTimeEntryRepositoryInterface
{
    /**
     * Ids of the user's entries. Reads only: what a flush that deletes the user settles after its commit.
     *
     * @return list<string>
     */
    public function entryIdsOfOwner(string $userId): array;

    /**
     * After the user's delete: no user, and this reference and name, on these entries. Returns the entries changed.
     *
     * @param list<string> $entryIds
     */
    public function settleDeletedOwner(array $entryIds, string $personRef, ?string $name): int;

    /**
     * Anonymises these entries, as {@see self::anonymiseOwner()} does. Returns the entries changed.
     *
     * @param list<string> $entryIds
     */
    public function anonymiseEntries(array $entryIds, DateTimeImmutable $at): int;

    /**
     * Clears the user of the user's entries, with the person reference and name. Returns the entries changed.
     */
    public function releaseOwner(string $userId, string $personRef, ?string $name): int;

    /**
     * Anonymises the user's entries: no user, name, reference, description or task title, the external id hashed, the
     * metadata down to the {@see TimeEntry::CLOSED_BY_USER_DELETION} mark. Returns the entries changed.
     */
    public function anonymiseOwner(string $userId, DateTimeImmutable $at): int;

    /**
     * Anonymises the ownerless entries of a person reference. Returns the entries changed.
     */
    public function anonymisePerson(string $personRef, DateTimeImmutable $at): int;

    /**
     * Anonymises the ownerless entries with no person reference (a raw delete with no release before it). Returns the
     * entries changed.
     */
    public function anonymiseUnidentified(DateTimeImmutable $at): int;

    /**
     * Anonymises the ownerless entries that ended before the cut-off: a retention term for names. Returns the entries changed.
     */
    public function anonymiseOwnerlessEndedBefore(DateTimeImmutable $cutOff, DateTimeImmutable $at): int;

    /**
     * Up to `$limit` ownerless entries of a person reference with an id after `$afterId`, by id.
     *
     * @return list<TimeEntry>
     */
    public function findByPersonRef(string $personRef, string $afterId, int $limit): array;

    /**
     * One page of the users' entries and the ownerless entries of the period, newest first.
     *
     * @param list<string> $userIds
     * @param list<string>|null $ownerlessScopeKeys scopes of the ownerless entries the viewer may see (null = every one)
     *
     * @return list<TimeEntry>
     */
    public function findPageWithOwnerless(array $userIds, ?array $ownerlessScopeKeys, DateTimeImmutable $from, DateTimeImmutable $to, bool $includeImported, int $offset, int $limit): array;

    /**
     * @param list<string> $userIds
     * @param list<string>|null $ownerlessScopeKeys
     */
    public function countWithOwnerless(array $userIds, ?array $ownerlessScopeKeys, DateTimeImmutable $from, DateTimeImmutable $to, bool $includeImported): int;
}
