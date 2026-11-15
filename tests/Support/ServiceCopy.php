<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use ReflectionClass;

use function array_key_exists;

/**
 * 1.27.0 (Beacon spec 239): a copy of a (readonly) service with some of its properties replaced, so a race test can
 * hook a collaborator of the container's own instance.
 */
final class ServiceCopy
{
    /**
     * @template T of object
     *
     * @param T $service
     * @param array<string, mixed> $replace property => value
     *
     * @return T
     */
    public static function with(object $service, array $replace): object
    {
        $class = new ReflectionClass($service);
        $copy  = $class->newInstanceWithoutConstructor();
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || !$property->isInitialized($service)) {
                continue;
            }
            $property->setValue($copy, array_key_exists($property->getName(), $replace) ? $replace[$property->getName()] : $property->getValue($service));
        }

        return $copy;
    }
}
