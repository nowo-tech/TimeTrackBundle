<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Subject\ActivityTypeCatalog;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;
use Nowo\TimeTrackBundle\Subject\AllocationGuard;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Since 2.1.0: builds the subject registry and allocation guard of the unit tests (Beacon spec 256) from the test
 * providers, as the compiler pass wires them in a container.
 */
final class AllocationFixtures
{
    /**
     * The registry of the given providers (keyed by their type, `allowsTask()` read from their class).
     */
    public static function registry(?LoggerInterface $logger = null, AllocationTestSubjectProvider ...$providers): TimeSubjectRegistry
    {
        $factories  = [];
        $allowsTask = [];
        foreach ($providers as $provider) {
            $type              = $provider::subjectType();
            $factories[$type]  = static fn (): AllocationTestSubjectProvider => $provider;
            $allowsTask[$type] = $provider::allowsTask();
        }

        return new TimeSubjectRegistry(new ServiceLocator($factories), $allowsTask, $logger);
    }

    /**
     * The guard over the given providers.
     *
     * @param list<AllocationTestSubjectProvider> $providers
     */
    public static function guard(array $providers, ?ActivityTypeProviderInterface $activityTypes = null, bool $requireAllocation = false, bool $requireActivityType = false, bool $allowWithoutTask = false, ?LoggerInterface $logger = null): AllocationGuard
    {
        return new AllocationGuard(self::registry($logger, ...$providers), new ActivityTypeCatalog($activityTypes, $logger), $requireAllocation, $requireActivityType, $allowWithoutTask);
    }
}
