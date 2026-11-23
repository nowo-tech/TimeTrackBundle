<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration\RemovedPorts;

use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinScopePolicy;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;

/**
 * 2.0: the 1.x port ids are gone (`…\Timesheet\TimesheetScopeProviderInterface` and the others), and the configured
 * service of a port is available, public, under its framework-free interface id instead, so another bundle (TaskBoard's
 * timesheet scope bridge) injects it by `TimesheetScopePolicyInterface`.
 */
final class TwinServiceIdsKernelTest extends TestCase
{
    public function testTheDefaultSingleScopeIsServedUnderTheTwinId(): void
    {
        $kernel = new FunctionalKernel(['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => StubTimesheetApproverProvider::class]], debug: false);
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            self::assertFalse($container->has('Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface'), 'the 1.x id is removed');
            self::assertTrue($container->has(TimesheetScopePolicyInterface::class));
            $policy = $container->get(TimesheetScopePolicyInterface::class);
            self::assertInstanceOf(SingleScopeProvider::class, $policy);
            self::assertSame(SingleScopeProvider::KEY, $policy->scopeKeyForUserTask(TimeTrackUserRef::ofId('App\User', '1'), 'task-1', null));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAConfiguredTwinIsServedUnderItsOwnId(): void
    {
        $kernel = new FunctionalKernel(['timesheets' => ['enabled' => true, 'scope_provider' => TwinScopePolicy::class, 'approver_provider' => StubTimesheetApproverProvider::class]], debug: false);
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            self::assertInstanceOf(TwinScopePolicy::class, $container->get(TimesheetScopePolicyInterface::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
