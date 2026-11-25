<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Port\Ports;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectProviderInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Since 2.1.0 (Beacon spec 256): wires the time subject providers and the activity type provider.
 *
 * - Every service tagged `nowo_time_track.time_subject_provider` (the bundle tags each
 *   {@see TimeSubjectProviderInterface} implementation for autoconfiguration) goes into a service locator keyed by its
 *   `subjectType()`, with the constant `type => allowsTask()` map, both given to {@see TimeSubjectRegistry}. The build
 *   fails on a class that cannot be known, that does not implement the interface, on a type that breaks
 *   {@see TimeSubjectRef::TYPE_PATTERN}, or on two providers for one type. The bundle's own built-in provider takes its
 *   configured type from the tag's `type` attribute.
 * - The `ActivityTypeProviderInterface` id the application defines (an alias to its service) becomes the
 *   `nowo_time_track.port.activity_types` slot.
 * - `entries.require_allocation: true` with `entries.allow_without_task: true` and no subject provider fails the build: a
 *   timer without a task could never be allocated, so every interactive stop of one would be refused.
 *
 * @internal
 */
final class TimeSubjectProvidersPass implements CompilerPassInterface
{
    /** The tag of the subject providers. */
    public const TAG = 'nowo_time_track.time_subject_provider';

    /** The slot of the activity type provider. */
    public const ACTIVITY_TYPES_SLOT = TimeTrackPortsPass::SLOT_PREFIX . 'activity_types';

    /**
     * @throws LogicException on a provider that cannot be registered, or a configuration that could never allocate
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias(ActivityTypeProviderInterface::class) || $container->hasDefinition(ActivityTypeProviderInterface::class)) {
            $container->setAlias(self::ACTIVITY_TYPES_SLOT, ActivityTypeProviderInterface::class)->setPublic(false);
        }
        if (!$container->hasDefinition(TimeSubjectRegistry::class)) {
            return;
        }

        $providers  = [];
        $allowsTask = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class      = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
            if ($definition->getFactory() !== null || !is_string($class) || !class_exists($class) || !is_a($class, TimeSubjectProviderInterface::class, true)) {
                throw new LogicException(sprintf('nowo_time_track: the time subject provider "%s" must be a service of a known class implementing "%s" (its subjectType() and allowsTask() are read when the container is compiled).', $id, TimeSubjectProviderInterface::class));
            }
            $tag  = $tags[0] ?? [];
            $type = is_array($tag) ? ($tag['type'] ?? null) : null;
            $type = is_string($type) && Ports::isBundleClass($class) ? $type : $class::subjectType();
            if (!TimeSubjectRef::isType($type)) {
                throw new LogicException(sprintf('nowo_time_track: the time subject provider "%s" answers for the type "%s", which does not match %s.', $id, $type, TimeSubjectRef::TYPE_PATTERN));
            }
            if (isset($providers[$type])) {
                throw new LogicException(sprintf('nowo_time_track: two time subject providers answer for the type "%s" ("%s" and "%s"); register one per type.', $type, (string) $providers[$type], $id));
            }
            $providers[$type]  = new Reference($id);
            $allowsTask[$type] = $class::allowsTask();
        }

        $parameters = $container->getParameterBag();
        if ($providers === [] && $parameters->has('nowo_time_track.entries.require_allocation') && $parameters->get('nowo_time_track.entries.require_allocation') === true
            && $parameters->has('nowo_time_track.entries.allow_without_task') && $parameters->get('nowo_time_track.entries.allow_without_task') === true) {
            throw new LogicException('nowo_time_track: entries.require_allocation is true with entries.allow_without_task true but no time subject provider is registered: a timer without a task could never be allocated. Register a TimeSubjectProviderInterface (or enable entries.subjects.scope_subject), or turn one of the options off.');
        }

        $container->getDefinition(TimeSubjectRegistry::class)
            ->setArgument('$providers', ServiceLocatorTagPass::register($container, $providers))
            ->setArgument('$allowsTask', $allowsTask);
    }
}
