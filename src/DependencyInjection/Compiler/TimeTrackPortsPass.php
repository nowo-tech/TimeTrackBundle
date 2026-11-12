<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
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
 * Wires the framework-free 1.23 twins of the host ports (REQ-ARCH-001 rule 3) and refuses a port service that
 * implements neither interface.
 *
 * Every configured port has one slot the bundle's services reference: the deprecated interface id (an alias the
 * extension points at the configured or built-in service) or, for the ports a service is configured for by option only,
 * an internal alias `nowo_time_track.port.<name>`. For each slot that exists:
 *
 * - the class behind it implements the deprecated interface: nothing changes (asked exactly as in 1.22, also when it
 *   implements the twin too);
 * - it implements only the twin: the slot is pointed at the twin's gate over that service;
 * - the class cannot be known before compilation (factory, synthetic, unknown class): the slot is pointed at a
 *   {@see Ports::port()} factory over that service, which decides per container at runtime (as is, gate or
 *   `LogicException`);
 * - it implements neither: the build fails.
 *
 * `UserReferenceResolverInterface` (an alias the documentation tells hosts to override): when the application defines
 * the 1.23 {@see UserReferenceProviderInterface} id and the deprecated alias still
 * points at the built-in resolver, the slot is pointed at the twin's gate. Defining both to different services fails
 * the build (keep the 1.23 one).
 *
 * Deprecations of the old interfaces reach the application through their `@deprecated` tag (Symfony's
 * DebugClassLoader, in debug mode), not from this pass. Runs before optimization.
 *
 * @internal
 */
final class TimeTrackPortsPass implements CompilerPassInterface
{
    /** Alias prefix of the ports configured by option only (no alias of the deprecated interface). */
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
        'user_reference'        => [Ports::PORTS['user_reference'][0], 'the UserReferenceResolverInterface alias'],
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
     * @throws LogicException on a port service that implements neither interface, or an ambiguous wiring
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
     * Points a slot at the twin's gate, or at a runtime factory, when the service does not implement the deprecated
     * interface.
     *
     * @throws LogicException when the service implements neither interface
     */
    private function wire(ContainerBuilder $container, string $name, string $slot, string $option): void
    {
        [$legacy, $twin, $gate] = Ports::PORTS[$name];
        $target                 = $this->resolve($container, $slot);
        $class                  = $this->classOf($container, $target);
        if ($class !== null && is_a($class, $legacy, true)) {
            return;
        }
        if ($class !== null && !is_a($class, $twin, true)) {
            throw new LogicException(sprintf('nowo_time_track: the service "%s" (%s) of %s must implement "%s" (or the deprecated "%s").', $target, $class, $option, $twin, $legacy));
        }

        if ($target === $slot) {
            // The slot is the service's own definition (e.g. a host service registered under the interface id): move it.
            $target = self::GATE_PREFIX . $name . '.inner';
            $container->setDefinition($target, $container->getDefinition($slot));
            $container->removeDefinition($slot);
        }

        $gateId = self::GATE_PREFIX . $name;
        if ($class === null) {
            $definition = (new Definition($legacy))->setFactory([Ports::class, 'port'])->setArguments([$name, new Reference($target)]);
        } else {
            $definition = new Definition($name === 'task_provider' && is_a($class, BatchTaskDirectoryInterface::class, true) ? BatchTaskDirectoryGate::class : $gate, [new Reference($target)]);
        }
        $container->setDefinition($gateId, $definition);
        $container->setAlias($slot, $gateId)->setPublic(false);
    }

    /**
     * The 1.23 `UserReferenceProviderInterface` defined by the application replaces the built-in resolver behind the
     * deprecated alias.
     *
     * @throws LogicException when both interfaces are pointed at different services
     */
    private function wireUserReferenceTwin(ContainerBuilder $container): void
    {
        [$legacy, $twin] = Ports::PORTS['user_reference'];
        if (!$container->hasAlias($twin) && !$container->hasDefinition($twin)) {
            return;
        }

        $twinService  = $this->resolve($container, $twin);
        $legacyTarget = $container->hasAlias($legacy) || $container->hasDefinition($legacy) ? $this->resolve($container, $legacy) : null;
        if ($legacyTarget === $twinService) {
            return; // One service implements both: asked through the deprecated interface until 2.0.
        }
        if ($legacyTarget !== null && $legacyTarget !== DoctrineUserReferenceResolver::class) {
            throw new LogicException(sprintf('"%s" is served by "%s" and the deprecated "%s" by "%s": keep only one of them (the 1.23 interface).', $twin, $twinService, $legacy, $legacyTarget));
        }

        $container->setAlias($legacy, $twin)->setPublic(false);
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
