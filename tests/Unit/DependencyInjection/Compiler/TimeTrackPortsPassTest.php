<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeTrackPortsPass;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface;
use Nowo\TimeTrackBundle\Port\BatchTaskDirectoryGate;
use Nowo\TimeTrackBundle\Port\MeetingSourceGate;
use Nowo\TimeTrackBundle\Port\Ports;
use Nowo\TimeTrackBundle\Port\TimesheetScopePolicyGate;
use Nowo\TimeTrackBundle\Port\UserReferenceProviderGate;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\Tests\Support\Ports\RecordingTwinPorts;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): the slots of the host ports follow the class behind them.
 */
final class TimeTrackPortsPassTest extends TestCase
{
    public function testADeprecatedImplementationKeepsItsWiring(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.meetings', StubMeetingProvider::class);
        $container->setAlias('nowo_time_track.port.meeting_provider', 'app.meetings');
        $container->register(StubTaskProvider::class, StubTaskProvider::class);
        $container->setAlias(TaskProviderInterface::class, StubTaskProvider::class);

        (new TimeTrackPortsPass())->process($container);

        self::assertSame('app.meetings', (string) $container->getAlias('nowo_time_track.port.meeting_provider'));
        self::assertSame(StubTaskProvider::class, (string) $container->getAlias(TaskProviderInterface::class));
        self::assertFalse($container->hasDefinition('nowo_time_track.port_gate.meeting_provider'));
    }

    public function testATwinIsServedThroughItsGate(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.meetings', RecordingTwinPorts::class);
        $container->setAlias('nowo_time_track.port.meeting_provider', 'app.meetings');
        $container->register('app.tasks', RecordingTwinPorts::class);
        $container->setAlias(TaskProviderInterface::class, 'app.tasks');

        (new TimeTrackPortsPass())->process($container);

        self::assertSame('nowo_time_track.port_gate.meeting_provider', (string) $container->getAlias('nowo_time_track.port.meeting_provider'));
        $gate = $container->getDefinition('nowo_time_track.port_gate.meeting_provider');
        self::assertSame(MeetingSourceGate::class, $gate->getClass());
        self::assertEquals([new Reference('app.meetings')], $gate->getArguments());
        self::assertSame(BatchTaskDirectoryGate::class, $container->getDefinition('nowo_time_track.port_gate.task_provider')->getClass(), 'a batch directory gets the batch gate');
    }

    public function testAServiceRegisteredUnderTheInterfaceIdIsMovedBehindTheGate(): void
    {
        $container = new ContainerBuilder();
        $container->register(TimesheetScopeProviderInterface::class, RecordingTwinPorts::class)->setPublic(true);
        $container->register('consumer', stdClass::class)->setProperty('scopes', new Reference(TimesheetScopeProviderInterface::class))->setPublic(true);

        (new TimeTrackPortsPass())->process($container);
        $container->compile();

        $consumer = $container->get('consumer');
        self::assertInstanceOf(stdClass::class, $consumer);
        self::assertInstanceOf(TimesheetScopePolicyGate::class, $consumer->scopes ?? null);
    }

    public function testAClassUnknownBeforeCompilationIsDecidedAtRuntime(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.meetings', MeetingProviderInterface::class)->setFactory([self::class, 'twin']);
        $container->setAlias('nowo_time_track.port.meeting_provider', 'app.meetings');
        $container->register('consumer', stdClass::class)->setProperty('meetings', new Reference('nowo_time_track.port.meeting_provider'))->setPublic(true);

        (new TimeTrackPortsPass())->process($container);
        $factory = $container->getDefinition('nowo_time_track.port_gate.meeting_provider');
        self::assertSame([Ports::class, 'port'], $factory->getFactory());
        $container->compile();

        $consumer = $container->get('consumer');
        self::assertInstanceOf(stdClass::class, $consumer);
        self::assertInstanceOf(MeetingSourceGate::class, $consumer->meetings ?? null);
    }

    public function testAnAliasToAServiceNotDefinedYetIsDecidedAtRuntime(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias('nowo_time_track.port.meeting_provider', 'app.later');

        (new TimeTrackPortsPass())->process($container);

        $factory = $container->getDefinition('nowo_time_track.port_gate.meeting_provider');
        self::assertSame([Ports::class, 'port'], $factory->getFactory());
        self::assertEquals(['meeting_provider', new Reference('app.later')], $factory->getArguments());
    }

    public function testAServiceImplementingNeitherFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.meetings', stdClass::class);
        $container->setAlias('nowo_time_track.port.meeting_provider', 'app.meetings');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_time_track: the service "app.meetings" (stdClass) of nowo_time_track.meetings.provider must implement "Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface" (or the deprecated "Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface").');

        (new TimeTrackPortsPass())->process($container);
    }

    public function testTheUserReferenceTwinReplacesTheBuiltInResolver(): void
    {
        $container = $this->withBuiltInResolver();
        $container->register('app.refs', RecordingTwinPorts::class);
        $container->setAlias(UserReferenceProviderInterface::class, 'app.refs');

        (new TimeTrackPortsPass())->process($container);

        self::assertSame('nowo_time_track.port_gate.user_reference', (string) $container->getAlias(UserReferenceResolverInterface::class));
        self::assertSame(UserReferenceProviderGate::class, $container->getDefinition('nowo_time_track.port_gate.user_reference')->getClass());
        self::assertEquals([new Reference('app.refs')], $container->getDefinition('nowo_time_track.port_gate.user_reference')->getArguments());
    }

    public function testTheUserReferenceTwinWithoutTheDeprecatedAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register(UserReferenceProviderInterface::class, RecordingTwinPorts::class);

        (new TimeTrackPortsPass())->process($container);

        self::assertSame('nowo_time_track.port_gate.user_reference', (string) $container->getAlias(UserReferenceResolverInterface::class));
    }

    public function testOneServiceBehindBothUserReferenceInterfacesIsAskedThroughTheDeprecatedOne(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.refs', DoctrineUserReferenceResolver::class);
        $container->setAlias(UserReferenceProviderInterface::class, 'app.refs');
        $container->setAlias(UserReferenceResolverInterface::class, 'app.refs');

        (new TimeTrackPortsPass())->process($container);

        self::assertSame('app.refs', (string) $container->getAlias(UserReferenceResolverInterface::class));
    }

    public function testBothUserReferenceInterfacesOnDifferentServicesFailTheBuild(): void
    {
        $container = $this->withBuiltInResolver();
        $container->register('app.legacy_refs', DoctrineUserReferenceResolver::class);
        $container->setAlias(UserReferenceResolverInterface::class, 'app.legacy_refs');
        $container->register('app.refs', RecordingTwinPorts::class);
        $container->setAlias(UserReferenceProviderInterface::class, 'app.refs');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('keep only one of them (the 1.23 interface)');

        (new TimeTrackPortsPass())->process($container);
    }

    public function testAMissingSlotIsSkipped(): void
    {
        $container = new ContainerBuilder();

        (new TimeTrackPortsPass())->process($container);

        self::assertFalse($container->hasDefinition('nowo_time_track.port_gate.meeting_provider'));
        self::assertFalse($container->hasAlias(UserReferenceResolverInterface::class));
    }

    /** Factory of the runtime-decision test. */
    public static function twin(): RecordingTwinPorts
    {
        return new RecordingTwinPorts();
    }

    private function withBuiltInResolver(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(DoctrineUserReferenceResolver::class, new Definition(DoctrineUserReferenceResolver::class));
        $container->setAlias(UserReferenceResolverInterface::class, DoctrineUserReferenceResolver::class);

        return $container;
    }
}
