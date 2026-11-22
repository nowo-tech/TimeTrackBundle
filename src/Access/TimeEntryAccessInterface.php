<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Access;

use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Event\TimeEntryIdAccessCheckEvent;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.37.0 (DDD-host checklist B1/B8, REQ-ARCH-001): whether a user may see or change one time entry, asked by
 * ids. A host with a private entity manager (or no Doctrine user) checks entry access without loading bundle entities in
 * its own entity manager and without re-implementing ownership in DQL.
 *
 * The user is a user id (`string|int`, the `getId()` of your user) or a {@see TimeTrackUserRef}:
 * - a reference that carries your security user object (`TimeTrackUserRef::of()`) is checked as that user;
 * - a bare id, or a reference without the object (`TimeTrackUserRef::ofId()`), is checked by its id, with the role
 *   names the reference carries (a bare id has none: the admin-role rule needs a reference with the roles). Your team
 *   and scope ports receive that reference.
 *
 * The rules are {@see TeamAccessGuard}'s: the owner; an admin role
 * (`security.admin_roles`); a manager of the owner (`security.manager_can_view_entries` /
 * `security.manager_can_edit_entries`); then the listeners of `nowo_time_track.time_entry.access_check` and of the
 * ids-only `nowo_time_track.time_entry.id_access_check` ({@see TimeEntryIdAccessCheckEvent}),
 * which may narrow but never grant an entry without a task outside the user's tenant scopes. An edit also needs a live
 * entry (not imported, with an owner) in a period that is neither locked nor submitted.
 *
 * Ids compare exactly (byte for byte): the entry id in SQL and again in PHP, so a look-alike id (other case, padded,
 * folded) never stands for another entry. An unknown id, a look-alike, an empty or unusable id, another user's entry and
 * an entry the user may not reach all give the same answer (false / null): the answer tells nothing about which.
 *
 * Every call reads the entry and asks the host ports again; nothing is kept between calls (FrankenPHP worker mode,
 * kernel reset false). An answer is a check at that moment: the bundle's own write services check again, under the
 * owner's lock, when they write (REQ-RUNTIME-003).
 */
interface TimeEntryAccessInterface
{
    /**
     * Whether the user may see the entry (owner, admin role, manager of the owner when
     * `security.manager_can_view_entries`; action `view` for the listeners).
     *
     * @param int|string|TimeTrackUserRef $user the user id, or a reference (see the class comment)
     */
    public function canViewEntry(TimeTrackUserRef|string|int $user, string $entryId): bool;

    /**
     * Whether the user may change the entry (owner, admin role, manager of the owner when
     * `security.manager_can_edit_entries`; action `edit` for the listeners; then the period lock). Imported, ownerless and
     * anonymised entries are never editable.
     *
     * @param int|string|TimeTrackUserRef $user the user id, or a reference (see the class comment)
     */
    public function canEditEntry(TimeTrackUserRef|string|int $user, string $entryId): bool;

    /**
     * The entry as a scalar snapshot when the user may see it ({@see self::canViewEntry()}), null otherwise (unknown,
     * look-alike, another user's, not reachable: the same null).
     *
     * @param int|string|TimeTrackUserRef $user the user id, or a reference (see the class comment)
     */
    public function viewableEntry(TimeTrackUserRef|string|int $user, string $entryId): ?TimeEntryView;
}
