<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Event\TaskEffortAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;

/**
 * Decides whether a viewer may read the entries of another user (self, admin roles, managed users) or edit an entry
 * (owner, manager, admin roles, then the access-check event).
 */
final readonly class TeamAccessGuard
{
    /**
     * @param list<string> $adminRoles
     */
    public function __construct(
        private TeamContextProviderInterface $teamContext,
        private EventDispatcherInterface $eventDispatcher,
        private array $adminRoles,
        private bool $managerCanViewEntries,
        private bool $managerCanEditEntries,
        private ?ManagerRegistry $managerRegistry = null,
        private ?PeriodLockGuard $periodLockGuard = null,
    ) {
    }

    /**
     * Whether the viewer may read the entries of the target user.
     */
    public function canViewUserEntries(UserInterface $viewer, string $targetUserId): bool
    {
        if (UserIdResolver::getId($viewer) === $targetUserId) {
            return true;
        }

        if ($this->hasAdminRole($viewer)) {
            return true;
        }

        if (!$this->managerCanViewEntries) {
            return false;
        }

        return in_array($targetUserId, $this->teamContext->getManagedUserIds($viewer), true);
    }

    /** Since 1.11: the access-check action of {@see self::canDeleteImportedEntry()}. */
    public const ACTION_DELETE_IMPORTED = 'delete_imported';

    /**
     * Whether the viewer may edit the entry (host code only; the bundle ships no edit route). Since 1.11 an imported
     * (read-only) entry is never editable: the access-check event is not even dispatched, so no listener can grant it.
     * Since 1.13 an entry of a locked or submitted period is never editable either: the period lock is checked **after**
     * the event, so a granting listener is ignored.
     */
    public function canEditEntry(UserInterface $editor, TimeEntry $entry): bool
    {
        return $this->isEditGranted($editor, $entry) && $this->isWritable($entry);
    }

    /**
     * Since 1.21: {@see self::canEditEntry()} without the period lock — the edit grant alone (owner, manager when
     * `security.manager_can_edit_entries`, admin roles, then the `edit` access-check event). Callers that tell a refusal
     * (403) from a lock (409) ask this first and then the period lock themselves. Imported entries and entries without a
     * user owner (ownerless or anonymised) are never granted.
     */
    public function isEditGranted(UserInterface $editor, TimeEntry $entry): bool
    {
        if ($entry->isReadOnly()) {
            return false;
        }

        $entryUser = $entry->getUser();
        if (!$entryUser instanceof UserInterface) {
            return false;
        }

        $granted = UserIdResolver::getId($editor) === UserIdResolver::getId($entryUser);
        if (!$granted && $this->managerCanEditEntries) {
            $granted = $this->teamContext->isManagerOf($editor, $entryUser);
        }
        if (!$granted) {
            $granted = $this->hasAdminRole($editor);
        }

        $event = new TimeEntryAccessCheckEvent($editor, $entry, 'edit', $granted);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK);

        return $event->isGranted();
    }

    /**
     * Since 1.11: whether the actor may delete an imported entry. Denied by default (also for its owner); the host
     * grants it through {@see TimeEntryAccessCheckEvent} with action `delete_imported` (e.g. project managers).
     * Always false for live entries, and (since 1.13) for entries of a locked or submitted period, whatever a listener
     * says.
     */
    public function canDeleteImportedEntry(UserInterface $actor, TimeEntry $entry): bool
    {
        if (!$entry->isReadOnly()) {
            return false;
        }

        $event = new TimeEntryAccessCheckEvent($actor, $entry, self::ACTION_DELETE_IMPORTED, false);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK);

        return $event->isGranted() && $this->isWritable($entry);
    }

    /**
     * Since 1.13 (owner decision 2026-11-05, audit GB-M3): whether the user may see the all-users total of logged time
     * on the task, i.e. can **manage** it. Denied by default (admin roles included); the host grants it through
     * {@see TaskEffortAccessCheckEvent}. Everyone else sees only their own total.
     */
    public function canSeeTaskEffortTotal(UserInterface $user, string $taskId): bool
    {
        $event = new TaskEffortAccessCheckEvent($user, $taskId);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TASK_EFFORT_ACCESS_CHECK);

        return $event->isGranted();
    }

    /**
     * Since 1.13: the period lock, evaluated after the listeners (a grant never overrides it).
     */
    private function isWritable(TimeEntry $entry): bool
    {
        return !$this->periodLockGuard instanceof PeriodLockGuard || $this->periodLockGuard->isEntryWritable($entry);
    }

    private function hasAdminRole(UserInterface $user): bool
    {
        if ($this->adminRoles === []) {
            return false;
        }

        ManagedEntityRefresher::refresh($this->managerRegistry, $user);

        return (bool) array_intersect($this->adminRoles, $user->getRoles());
    }
}
