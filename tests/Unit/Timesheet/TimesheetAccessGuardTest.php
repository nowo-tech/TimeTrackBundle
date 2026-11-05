<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Timesheet;

use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Event\TimesheetAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.13 (AC-05, AC-07): tenant-aware decisions, no self-approval for anybody, deny-only listeners.
 */
final class TimesheetAccessGuardTest extends TestCase
{
    private TestUser $owner;

    private TestUser $approver;

    private TestUser $admin;

    private EventDispatcher $dispatcher;

    /** @var list<TimesheetAccessCheckEvent> */
    private array $checks = [];

    private TimesheetAccessGuard $guard;

    protected function setUp(): void
    {
        $this->owner      = new TestUser('7', 'ana@example.com');
        $this->approver   = new TestUser('9', 'boss@example.com');
        $this->admin      = new TestUser('1', 'admin@example.com', ['ROLE_ADMIN', 'ROLE_PAYROLL']);
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(TimeTrackEvents::TIMESHEET_ACCESS_CHECK, function (TimesheetAccessCheckEvent $event): void {
            $this->checks[] = $event;
        });
        $scopes = new FixedScopeProvider(['7' => ['org-a', 'org-b'], '9' => ['org-a', 'org-b'], '1' => ['org-a']]);
        // 9 approves 7 in org-a only; 9 is a member of org-b like 7; the admin also "approves" themselves (ignored).
        $approvers   = new StubTimesheetApproverProvider(['org-a' => ['9' => ['7', '9'], '1' => ['1']]]);
        $this->guard = new TimesheetAccessGuard($scopes, $approvers, $this->dispatcher, ['ROLE_ADMIN'], false, ['ROLE_PAYROLL']);
    }

    public function testOwnersSeeTheirOwnScopesWithoutAnAuditEvent(): void
    {
        self::assertTrue($this->guard->canView($this->owner, '7', 'org-a'));
        self::assertFalse($this->guard->canView($this->owner, '7', 'org-x'), 'a scope the owner is not in');
        self::assertSame([], $this->checks);
        self::assertTrue($this->guard->canSubmit($this->owner, '7', 'org-a'));
        self::assertFalse($this->guard->canSubmit($this->approver, '7', 'org-a'), 'no submit on behalf');
        self::assertTrue($this->guard->canSubmit($this->owner, '7', 'org-b', TimesheetAccessGuard::WITHDRAW));
        self::assertSame(TimesheetAccessGuard::WITHDRAW, $this->lastCheck()->getAction());
    }

    public function testApproversAreScopedAndNeverApproveThemselves(): void
    {
        self::assertTrue($this->guard->canView($this->approver, '7', 'org-a'));
        self::assertFalse($this->guard->canView($this->approver, '7', 'org-b'), 'member, not approver, in org-b');
        self::assertTrue($this->guard->canDecide($this->approver, '7', 'org-a'));
        self::assertTrue($this->guard->canDecide($this->approver, '7', 'org-a', TimesheetAccessGuard::REJECT));
        self::assertFalse($this->guard->canDecide($this->approver, '9', 'org-a'), 'the provider says yes, the bundle says no');
        self::assertFalse($this->guard->canDecide($this->admin, '1', 'org-a'), 'no self-approval for admin roles either');
        self::assertTrue($this->guard->canAdjustBalance($this->approver, '7', 'org-a'));
        self::assertFalse($this->guard->canAdjustBalance($this->owner, '7', 'org-a'), 'no self-adjustment');
        self::assertSame(['7'], $this->guard->reviewableUserIds($this->approver, 'org-a'));
        self::assertSame([], $this->guard->reviewableUserIds($this->approver, 'org-x'));
        self::assertSame(TimesheetActorRole::Owner, $this->guard->roleOf($this->owner, '7', 'org-a'));
        self::assertSame(TimesheetActorRole::Approver, $this->guard->roleOf($this->approver, '7', 'org-a'));
        self::assertSame(TimesheetActorRole::Admin, $this->guard->roleOf($this->admin, '7', 'org-a'));

        $view = $this->checks[0];
        self::assertSame($this->approver, $view->getViewer());
        self::assertSame('7', $view->getOwnerId());
        self::assertSame('org-a', $view->getScopeKey());
        self::assertSame(TimesheetAccessGuard::VIEW, $view->getAction());
        self::assertTrue($view->isGranted());
    }

    public function testAdminRolesSeeNothingUnlessConfiguredAndReopenWithTheirRole(): void
    {
        self::assertFalse($this->guard->canView($this->admin, '7', 'org-a'));
        self::assertTrue($this->guard->canReopen($this->admin, '7', 'org-a'), 'timesheets.reopen_roles');
        self::assertFalse($this->guard->canReopen($this->admin, '7', 'org-b'), 'not in that scope');
        self::assertTrue($this->guard->canReopen($this->approver, '7', 'org-a'));
        self::assertFalse($this->guard->canReopen($this->owner, '7', 'org-a'));

        $viewing = new TimesheetAccessGuard(new FixedScopeProvider(['1' => ['org-a']]), null, null, ['ROLE_ADMIN'], true);
        self::assertTrue($viewing->canView($this->admin, '7', 'org-a'));
        self::assertFalse($viewing->canView($this->admin, '7', 'org-b'));
        self::assertFalse($viewing->canDecide($this->admin, '7', 'org-a'), 'no approver provider: nobody approves');
        self::assertSame([], $viewing->reviewableUserIds($this->admin, 'org-a'));
        self::assertFalse((new TimesheetAccessGuard(null, null))->canView($this->owner, '7', 'org-a'), 'no scope provider: nothing');
        self::assertFalse((new TimesheetAccessGuard(new FixedScopeProvider(['7' => ['org-a']]), null, null, [], false, []))->canReopen($this->owner, '8', 'org-a'));
    }

    private function lastCheck(): TimesheetAccessCheckEvent
    {
        $check = end($this->checks);
        self::assertInstanceOf(TimesheetAccessCheckEvent::class, $check);

        return $check;
    }

    public function testListenersMayDenyButNeverGrant(): void
    {
        $this->dispatcher->addListener(TimeTrackEvents::TIMESHEET_ACCESS_CHECK, static function (TimesheetAccessCheckEvent $event): void {
            if ($event->getAction() === TimesheetAccessGuard::REOPEN) {
                $event->deny();
            }
        });

        self::assertFalse($this->guard->canReopen($this->approver, '7', 'org-a'), 'payroll cut-off listener');
        self::assertFalse($this->guard->canDecide($this->approver, '7', 'org-b'));
        self::assertTrue($this->guard->hasScope($this->owner, 'org-b'));
        self::assertCount(2, $this->guard->scopesOf($this->owner));
    }
}
