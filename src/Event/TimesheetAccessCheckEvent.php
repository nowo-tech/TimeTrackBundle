<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Event;

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
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
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
}
