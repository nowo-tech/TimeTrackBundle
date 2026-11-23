<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface;
use Nowo\TimeTrackBundle\Port\BatchTaskDirectoryGate;
use Nowo\TimeTrackBundle\Port\Ports;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Wires the framework-free host ports (the 1.23 twins, REQ-ARCH-001 rule 3; the only host port interfaces since 2.0)
 * and refuses a port service that does not implement its twin.
 *
 * Every configured port has one slot the bundle's services reference: the id of the bundle's internal shape of the
 * port (an alias the extension points at the configured or built-in service) or, for the ports a service is
 * configured for by option only, an internal alias `nowo_time_track.port.<name>`. For each slot that exists:
 *
 * - the class behind it implements the twin: the slot is pointed at the twin's gate over that service;
 * - it is one of the bundle's own defaults (a `Nowo\TimeTrackBundle\` class on the internal shape): nothing changes;
 * - the class cannot be known before compilation (factory, synthetic, unknown class): the slot is pointed at a
 *   {@see Ports::port()} factory over that service, which decides per container at runtime (gate, as is or
 *   `LogicException`);
 * - anything else: the build fails.
 *
 * The 1.23 {@see UserReferenceProviderInterface} id defined by the application replaces the built-in resolver.
 * A service still on one of the interfaces 2.0 removed is refused before this pass by {@see RemovedPortsPass}. Runs
 * before optimization.
 *
 * @internal
 */
final class TimeTrackPortsPass implements CompilerPassInterface
{
    /** Alias prefix of the ports configured by option only. */
    public const SLOT_PREFIX = 'nowo_time_track.port.';

    /** Service id prefix of the gates and runtime factories this pass registers. */
    public const GATE_PREFIX = 'nowo_time_track.port_gate.';

    /**
     * Port => [slot id, configuration option named in errors].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SLOTS = [
        'task_provider'         => [Ports::PORTS['task_provider'][0], 'nowo_time_track.task_provider'],
        'team_context_provider' => [Ports::PORTS['team_context_provider'][0], 'nowo_time_track.team_context_provider'],
        'user_reference'        => [Ports::PORTS['user_reference'][0], 'the UserReferenceProviderInterface alias'],
        'scope_provider'        => [Ports::PORTS['scope_provider'][0], 'nowo_time_track.timesheets.scope_provider'],
        'task_key_resolver'     => [Ports::PORTS['task_key_resolver'][0], 'nowo_time_track.calendar.task_key_resolver'],
        'credential_issuer'     => [Ports::PORTS['credential_issuer'][0], 'nowo_time_track.client_api.credential_issuer'],
        'task_access_checker'   => [Ports::PORTS['task_access_checker'][0], 'nowo_time_track.client_api.task_access_checker'],
        'profile_provider'      => [Ports::PORTS['profile_provider'][0], 'nowo_time_track.client_api.profile_provider'],
        'working_calendar'      => [Ports::PORTS['working_calendar'][0], 'nowo_time_track.working_time.calendar_provider'],
        'worked_time_source'    => [Ports::PORTS['worked_time_source'][0], 'nowo_time_track.working_time.worked_time_source'],
        'credential_provider'   => [self::SLOT_PREFIX . 'credential_provider', 'nowo_time_track.calendar.credential_provider'],
        'meeting_provider'      => [self::SLOT_PREFIX . 'meeting_provider', 'nowo_time_track.meetings.provider'],
        'workday_provider'      => [self::SLOT_PREFIX . 'workday_provider', 'nowo_time_track.client_api.workday_provider'],
        'idle_check_provider'   => [self::SLOT_PREFIX . 'idle_check_provider', 'nowo_time_track.client_api.idle_check.provider'],
        'approver_provider'     => [self::SLOT_PREFIX . 'approver_provider', 'nowo_time_track.timesheets.approver_provider'],
    ];

    /**
     * @throws LogicException on a port service that does not implement its twin, or an ambiguous wiring
     */
    public function process(ContainerBuilder $container): void
    {
        $this->wireUserReferenceTwin($container);

        foreach (self::SLOTS as $name => [$slot, $option]) {
            if (!$container->hasAlias($slot) && !$container->hasDefinition($slot)) {
                continue;
            }
            $this->wire($container, $name, $slot, $option);
        }
    }

    /**
     * Points a slot at the twin's gate, or at a runtime factory, unless it holds one of the bundle's own defaults.
     *
     * @throws LogicException when the service does not implement the twin
     */
    private function wire(ContainerBuilder $container, string $name, string $slot, string $option): void
    {
        [$internal, $twin, $gate] = Ports::PORTS[$name];
        $target                   = $this->resolve($container, $slot);
        $class                    = $this->classOf($container, $target);
        if ($class !== null && !is_a($class, $twin, true)) {
            if (is_a($class, $internal, true) && Ports::isBundleClass($class)) {
                return; // One of the bundle's own defaults.
            }

            throw new LogicException(sprintf('nowo_time_track: the service "%s" (%s) of %s must implement "%s" (since 2.0 the only host interface of this port; see UPGRADING "To 2.0.0").', $target, $class, $option, $twin));
        }

        if ($target === $slot) {
            // The slot is the service's own definition (e.g. a host service registered under the slot id): move it.
            $target = self::GATE_PREFIX . $name . '.inner';
            $container->setDefinition($target, $container->getDefinition($slot));
            $container->removeDefinition($slot);
        }

        $gateId = self::GATE_PREFIX . $name;
        if ($class === null) {
            $definition = (new Definition($internal))->setFactory([Ports::class, 'port'])->setArguments([$name, new Reference($target)]);
        } else {
            $definition = new Definition($name === 'task_provider' && is_a($class, BatchTaskDirectoryInterface::class, true) ? BatchTaskDirectoryGate::class : $gate, [new Reference($target)]);
        }
        $container->setDefinition($gateId, $definition);
        $container->setAlias($slot, $gateId)->setPublic(false);
    }

    /**
     * The 1.23 `UserReferenceProviderInterface` defined by the application replaces the built-in resolver behind the
     * slot.
     */
    private function wireUserReferenceTwin(ContainerBuilder $container): void
    {
        [$internal, $twin] = Ports::PORTS['user_reference'];
        if (!$container->hasAlias($twin) && !$container->hasDefinition($twin)) {
            return;
        }

        $container->setAlias($internal, $twin)->setPublic(false);
    }

    /**
     * The service id an alias chain ends at.
     */
    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * @return class-string|null null when the class cannot be known before the container is compiled
     */
    private function classOf(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->hasDefinition($id)) {
            return null;
        }

        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
