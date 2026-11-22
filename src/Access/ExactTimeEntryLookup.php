<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Access;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Repository\FindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;

use function strlen;

/**
 * Since 1.37.0 (audit LQ rule): one entry by its exact id. The store compares the id as a bound parameter (`id = ?`, no
 * pattern, no case folding asked for); the id of the row it returns is compared again in PHP with `===`, so a
 * case-folding or padding collation never lets a look-alike id stand for another entry. Stateless.
 *
 * @internal
 */
final class ExactTimeEntryLookup
{
    /** Longest entry id (the `id` column is 36 characters). */
    public const MAX_ID_LENGTH = 36;

    /**
     * The entry with exactly this id, or null (none, a look-alike, an empty or too long id, or a store that cannot look
     * entries up by id).
     */
    public static function find(TimeEntryRepositoryInterface $entries, string $entryId): ?TimeEntry
    {
        if ($entryId === '' || strlen($entryId) > self::MAX_ID_LENGTH || !$entries instanceof FindableTimeEntryRepositoryInterface) {
            return null;
        }
        $entry = $entries->findById($entryId);

        return $entry instanceof TimeEntry && $entry->getId() === $entryId ? $entry : null;
    }
}
