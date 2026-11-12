<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 1.13 ({@see TimeTrackEvents::TIMESHEET_ACCESS_CHECK}): **deny-only** check of a timesheet action after the
 * bundle decided (`view`, `submit`, `withdraw`, `approve`, `reject`, `reopen`, `adjust_balance`). A listener may deny
 * (e.g. reopen after the payroll cut-off); it can never grant what the providers refused. Fired on every non-owner
 * view, so the host can audit who viewed whose timesheet.
 */
final class TimesheetAccessCheckEvent extends Event
{
    public const ACTIONS = ['view', 'submit', 'withdraw', 'approve', 'reject', 'reopen', 'adjust_balance'];

    /**
     * @param string $ownerId Opaque id of the timesheet owner
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private readonly string $ownerId,
        private readonly string $scopeKey,
        private readonly string $action,
        private bool $granted,
    ) {
    }

    /**
     * The user asking.
     *
     * @deprecated since 1.23.0, use {@see self::viewerRef()} (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Since 1.23.0: framework-free reference of {@see self::getViewer()} (REQ-ARCH-001 rule 3): class, id, identifier and
     * roles; the object itself through `viewerRef()->user()`. Built per call: compare with `isSameUserAs()`.
     */
    public function viewerRef(): TimeTrackUserRef
    {
        return TimeTrackUserRefs::fromUser($this->viewer);
    }

    /**
     * Opaque id of the owner.
     */
    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    /**
     * Tenant scope.
     */
    public function getScopeKey(): string
    {
        return $this->scopeKey;
    }

    /**
     * Requested action.
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Current decision.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }

    /**
     * Denies the action (there is no grant).
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = false;
    }

    /** Since 1.18: id of {@see self::getViewer()} as a string (`getId()`), for listeners that keep only ids. */
    public function getViewerId(): string
    {
        return UserIdResolver::getId($this->viewer);
    }
}
