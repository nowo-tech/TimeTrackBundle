<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration\RemovedPorts;

use LogicException;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 2.0 (REQ-ARCH-001 rule 3, audit lesson W1-1 / XR-WF-M1): through the real TimeTrackBundle::build(), an application
 * service still on a host port interface removed in 2.0 fails the container build with a message naming the service,
 * the removed interface and its 1.23 twin. Without the check Symfony would drop it silently (an autodiscovered class
 * that cannot be loaded, a decorator or an alias of a missing id) and TimeTrack would fall back to its default: a host
 * restriction of the task access check would disappear (wider access), a host task directory or timesheet scope would
 * be replaced by the stub ones. Fixtures: tests/Fixtures/RemovedPorts (one directory per case).
 */
final class RemovedPortsKernelTest extends TestCase
{
    private const FIXTURES = 'Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\\';

    /**
     * @return iterable<string, array{string, string, string, array<string, mixed>}> fixture directory, the service the message names, the removed interface, TimeTrack configuration
     */
    public static function removedPorts(): iterable
    {
        yield '#[AsDecorator] of the task access checker id' => ['DecoratorOfTaskAccess', self::FIXTURES . 'DecoratorOfTaskAccess\RestrictedTaskAccess', 'Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface', []];
        yield 'a loadable #[AsDecorator] of the scope provider id, onInvalid: IGNORE' => ['IgnoredDecoratorOfScope', self::FIXTURES . 'IgnoredDecoratorOfScope\OneScope', 'Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface', []];
        yield '#[AsAlias] of the task provider id' => ['AliasOfTaskProvider', self::FIXTURES . 'AliasOfTaskProvider\HostTasks', 'Nowo\TimeTrackBundle\Integration\TaskProviderInterface', []];
        yield 'a class implementing only the scope provider' => ['ImplementsScopeProvider', self::FIXTURES . 'ImplementsScopeProvider\OldScopes', 'Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface', []];
        yield 'a class implementing a removed profile extra' => ['ImplementsProfileExtra', self::FIXTURES . 'ImplementsProfileExtra\OldDaysOff', 'Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface', []];
        yield 'task_provider configured with a class implementing only the removed interface' => ['ConfiguredTaskProvider', self::FIXTURES . 'ConfiguredTaskProvider\OldTasks', 'Nowo\TimeTrackBundle\Integration\TaskProviderInterface', ['task_provider' => self::FIXTURES . 'ConfiguredTaskProvider\OldTasks']];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('removedPorts')]
    public function testAServiceOnARemovedPortFailsTheBuild(string $fixture, string $service, string $removed, array $config): void
    {
        $kernel = new FunctionalKernel($config, debug: false, serviceFixture: $fixture);
        try {
            $kernel->boot();
            self::fail('the build must fail');
        } catch (LogicException $failure) {
            self::assertSame(RemovedPortsPass::message($service, $removed), $failure->getMessage());
            self::assertStringContainsString('deprecated in 1.23.0 and removed in 2.0', $failure->getMessage());
            self::assertStringContainsString(RemovedPortsPass::REMOVED[$removed][0], $failure->getMessage(), 'the twin is named');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAnUnrelatedMissingInterfaceKeepsItsLazyErrorAndTheBuildSucceeds(): void
    {
        $kernel = new FunctionalKernel(debug: false, serviceFixture: 'UnrelatedMissing');
        $kernel->boot();
        try {
            self::assertFalse($kernel->getContainer()->has(self::FIXTURES . 'UnrelatedMissing\PolicyAdapter'), 'Symfony dropped the unused class as before');
            self::assertNull(RemovedPortsPass::named('Class "Nowo\TimeTrackBundle\Integration\TaskProviderInterfaceOfAnotherPackage" not found'));
        } finally {
            $kernel->shutdown();
        }
    }
}
