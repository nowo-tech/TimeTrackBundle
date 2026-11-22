<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Access\TimeEntryAccessInterface;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Event\TaskEffortAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryIdAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;

/**
 * Decides whether a viewer may read the entries of another user (self, admin roles, managed users) or edit an entry
 * (owner, manager, admin roles, then the access-check event). Since 1.36.0 an entry without a task is reachable by
 * anyone but its owner only within the actor's own tenant scopes ({@see self::isWithinTenant()}). Since 1.37.0 every entry
 * check also dispatches the ids-only {@see TimeEntryIdAccessCheckEvent} after {@see TimeEntryAccessCheckEvent}, and
 * {@see self::canViewEntry()} decides whether a viewer may read one entry (the id-based port
 * {@see TimeEntryAccessInterface} asks it). Stateless: every answer is read per call
 * (FrankenPHP worker mode, kernel reset false).
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
        // Since 1.36.0: the tenant scopes of the actor, for entries without a task (null = a single-tenant host).
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
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

    /** Since 1.37.0: the access-check action of {@see self::canViewEntry()}. */
    public const ACTION_VIEW = 'view';

    /** Since 1.37.0: the access-check action of {@see self::isEditGranted()} (the value it always had). */
    public const ACTION_EDIT = 'edit';

    /**
     * Since 1.37.0 (DDD-host checklist B1/B8): whether the viewer may read this entry: its owner, an admin role, or a
     * manager of the owner when `security.manager_can_view_entries` is true ({@see self::canViewUserEntries()}); an
     * ownerless or anonymised entry only an admin role. Then {@see TimeEntryAccessCheckEvent} and
     * {@see TimeEntryIdAccessCheckEvent} with action `view` may grant or deny it, but an entry without a task stays
     * within the viewer's tenant scopes ({@see self::isWithinTenant()}, checked before and after the listeners).
     */
    public function canViewEntry(UserInterface $viewer, TimeEntry $entry): bool
    {
        $owner   = $entry->getUserId();
        $granted = $owner !== null ? $this->canViewUserEntries($viewer, $owner) : $this->hasAdminRole($viewer);

        return $this->decide($viewer, $entry, self::ACTION_VIEW, $granted);
    }

    /** Since 1.21: the access-check action of {@see self::isBillableEditGranted()} (audit MV-T-L3). */
    public const ACTION_EDIT_BILLABLE = 'edit_billable';

    /**
     * Since 1.21 (audit MV-T-L3): whether the editor may set the entry's `billable` flag. The default is the edit grant
     * ({@see self::isEditGranted()}, which dispatches the `edit` check); then {@see TimeEntryAccessCheckEvent} with action
     * `edit_billable` lets a host grant or deny it on its own (e.g. only project managers decide what is billable).
     * Imported entries and entries without a user owner are never granted, and no event is dispatched for them. The
     * period lock is not part of it: callers check it themselves.
     */
    public function isBillableEditGranted(UserInterface $editor, TimeEntry $entry): bool
    {
        if ($entry->isReadOnly() || !$entry->getUser() instanceof UserInterface) {
            return false;
        }

        // Since 1.36.0: a listener cannot grant another tenant's entry without a task.
        return $this->decide($editor, $entry, self::ACTION_EDIT_BILLABLE, $this->isEditGranted($editor, $entry));
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

        // Since 1.36.0: checked again after the listeners, so a grant never crosses tenants for an entry without a task.
        return $this->decide($editor, $entry, self::ACTION_EDIT, $granted);
    }

    /**
     * Since 1.36.0 (Beacon spec 250): an entry without a task has no board or task to derive access from, so anyone but
     * its owner (a manager, an admin role, a granting listener) reaches it only within one of their own tenant scopes:
     * the entry's scope key must be one of the actor's, byte for byte (`===`). An entry without a scope is then the
     * owner's only. Entries with a task, the owner, and a host without a scope provider (single tenant) are unaffected.
     */
    public function isWithinTenant(UserInterface $actor, TimeEntry $entry): bool
    {
        if ($entry->hasTask() || !$this->scopeProvider instanceof TimesheetScopeProviderInterface) {
            return true;
        }
        $owner = $entry->getUserId();
        if ($owner !== null && $owner === UserIdResolver::getId($actor)) {
            return true;
        }

        return ScopeKeys::isOneOf($entry->getScopeKey(), ScopeKeys::ofUser($this->scopeProvider, $actor, new NullLogger()));
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

        // Since 1.37.0 the tenant rule of entries without a task applies here too (a listener never grants across tenants).
        return $this->decide($actor, $entry, self::ACTION_DELETE_IMPORTED, false) && $this->isWritable($entry);
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
     * Since 1.37.0: one entry decision. The tenant rule ({@see self::isWithinTenant()}, read once per call) bounds the
     * starting decision, then {@see TimeEntryAccessCheckEvent} and the ids-only {@see TimeEntryIdAccessCheckEvent} (which
     * starts from the first one's answer) may change it, and the tenant rule applies again to the final answer: listeners
     * narrow, but never widen across tenants.
     */
    private function decide(UserInterface $actor, TimeEntry $entry, string $action, bool $granted): bool
    {
        $within = $this->isWithinTenant($actor, $entry);
        $event  = new TimeEntryAccessCheckEvent($actor, $entry, $action, $granted && $within);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIME_ENTRY_ACCESS_CHECK);
        $ids = new TimeEntryIdAccessCheckEvent(UserIdResolver::getId($actor), $entry->getId(), $entry->getUserId(), $entry->taskIdOrNull(), $entry->getScopeKey(), $action, $event->isGranted() && $within);
        $this->eventDispatcher->dispatch($ids, TimeTrackEvents::TIME_ENTRY_ID_ACCESS_CHECK);

        return $ids->isGranted() && $within;
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
