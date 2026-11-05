<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Event\TimesheetAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_intersect;

/**
 * Since 1.13: every timesheet, balance and ledger decision (contract §11.1); without a scope provider (features off)
 * nobody has a scope, so everything is refused. Tenant-aware: the scope must be one of
 * the actor's scopes ({@see TimesheetScopeProviderInterface::scopesFor()}), approvers come from the host provider for
 * that scope, `security.admin_roles` see nothing unless `timesheets.admin_roles_view` is on or they are approvers, and
 * nobody approves, adjusts or reopens their own timesheet (admins included). After the bundle decides,
 * {@see TimesheetAccessCheckEvent} lets a listener deny (never grant). Callers answer 404 for other users' resources
 * whatever the reason (no existence oracle).
 */
final readonly class TimesheetAccessGuard
{
    public const VIEW = 'view';

    public const SUBMIT = 'submit';

    public const WITHDRAW = 'withdraw';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const REOPEN = 'reopen';

    public const ADJUST_BALANCE = 'adjust_balance';

    /**
     * @param list<string> $adminRoles `security.admin_roles`
     * @param list<string> $reopenRoles `timesheets.reopen_roles`
     */
    public function __construct(
        private ?TimesheetScopeProviderInterface $scopeProvider,
        private ?TimesheetApproverProviderInterface $approverProvider,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private array $adminRoles = [],
        private bool $adminRolesView = false,
        private array $reopenRoles = [],
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * Whether the scope is one of the user's scopes.
     */
    public function hasScope(UserInterface $user, string $scopeKey): bool
    {
        foreach ($this->scopesOf($user) as $scope) {
            if ($scope->key === $scopeKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user's scopes (the current one first).
     *
     * @return list<TimesheetScope>
     */
    public function scopesOf(UserInterface $user): array
    {
        return $this->scopeProvider?->scopesFor($user) ?? [];
    }

    /**
     * Whether the viewer may see the owner's timesheet, balance, ledger and audit trail in the scope.
     */
    public function canView(UserInterface $viewer, string $ownerId, string $scopeKey): bool
    {
        if ($this->isOwner($viewer, $ownerId)) {
            return $this->hasScope($viewer, $scopeKey);
        }

        $granted = $this->isApprover($viewer, $ownerId, $scopeKey)
            || ($this->adminRolesView && $this->hasScope($viewer, $scopeKey) && $this->hasRole($viewer, $this->adminRoles));

        return $this->check($viewer, $ownerId, $scopeKey, self::VIEW, $granted);
    }

    /**
     * Whether the actor may submit or withdraw (owner only: no submit on behalf).
     */
    public function canSubmit(UserInterface $actor, string $ownerId, string $scopeKey, string $action = self::SUBMIT): bool
    {
        return $this->check($actor, $ownerId, $scopeKey, $action, $this->isOwner($actor, $ownerId) && $this->hasScope($actor, $scopeKey));
    }

    /**
     * Whether the actor may approve or reject (an approver of the owner in the scope, never the owner).
     */
    public function canDecide(UserInterface $actor, string $ownerId, string $scopeKey, string $action = self::APPROVE): bool
    {
        return $this->check($actor, $ownerId, $scopeKey, $action, $this->isApprover($actor, $ownerId, $scopeKey));
    }

    /**
     * Whether the actor may reopen a locked period (an approver, or a holder of `timesheets.reopen_roles` in the
     * scope; never the owner).
     */
    public function canReopen(UserInterface $actor, string $ownerId, string $scopeKey): bool
    {
        $granted = $this->isApprover($actor, $ownerId, $scopeKey)
            || (!$this->isOwner($actor, $ownerId) && $this->hasScope($actor, $scopeKey) && $this->hasRole($actor, $this->reopenRoles));

        return $this->check($actor, $ownerId, $scopeKey, self::REOPEN, $granted);
    }

    /**
     * Whether the actor may add ledger lines for the owner (an approver; no self-adjustment).
     */
    public function canAdjustBalance(UserInterface $actor, string $ownerId, string $scopeKey): bool
    {
        return $this->check($actor, $ownerId, $scopeKey, self::ADJUST_BALANCE, $this->isApprover($actor, $ownerId, $scopeKey));
    }

    /**
     * Owner ids the approver reviews in the scope (empty when the scope is not theirs or there is no provider).
     *
     * @return list<string>
     */
    public function reviewableUserIds(UserInterface $approver, string $scopeKey): array
    {
        if (!$this->approverProvider instanceof TimesheetApproverProviderInterface || !$this->hasScope($approver, $scopeKey)) {
            return [];
        }
        $self = UserIdResolver::getId($approver);
        $ids  = [];
        foreach ($this->approverProvider->reviewableUserIds($approver, $scopeKey) as $id) {
            if ((string) $id !== $self) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * The actor's role for the audit trail.
     */
    public function roleOf(UserInterface $actor, string $ownerId, string $scopeKey): TimesheetActorRole
    {
        return match (true) {
            $this->isOwner($actor, $ownerId)               => TimesheetActorRole::Owner,
            $this->isApprover($actor, $ownerId, $scopeKey) => TimesheetActorRole::Approver,
            default                                        => TimesheetActorRole::Admin,
        };
    }

    /**
     * Whether the actor is the owner.
     */
    public function isOwner(UserInterface $actor, string $ownerId): bool
    {
        return UserIdResolver::getId($actor) === $ownerId;
    }

    /**
     * Approver of the owner in a scope of theirs, never of themselves.
     */
    private function isApprover(UserInterface $actor, string $ownerId, string $scopeKey): bool
    {
        return !$this->isOwner($actor, $ownerId)
            && $this->approverProvider instanceof TimesheetApproverProviderInterface
            && $this->hasScope($actor, $scopeKey)
            && $this->approverProvider->canApprove($actor, $ownerId, $scopeKey);
    }

    /**
     * @param list<string> $roles
     */
    private function hasRole(UserInterface $user, array $roles): bool
    {
        if ($roles === []) {
            return false;
        }
        ManagedEntityRefresher::refresh($this->managerRegistry, $user);

        return array_intersect($roles, $user->getRoles()) !== [];
    }

    /**
     * Deny-only listener check: fired on every decision except the owner's own view; a refused action stays refused.
     */
    private function check(UserInterface $actor, string $ownerId, string $scopeKey, string $action, bool $granted): bool
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return $granted;
        }
        $event = new TimesheetAccessCheckEvent($actor, $ownerId, $scopeKey, $action, $granted);
        $this->eventDispatcher->dispatch($event, TimeTrackEvents::TIMESHEET_ACCESS_CHECK);

        return $granted && $event->isGranted();
    }
}
