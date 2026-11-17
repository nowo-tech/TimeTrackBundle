<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Routing;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;

/**
 * Since 1.28 (REQ-SF-004): reads the `#[Route]` attributes of one bundle controller class and sets `_controller` to
 * `Class::method`, the same value the 1.27 loader wrote. {@see TimeTrackRouteLoader} owns it (no service, no
 * `routing.loader` tag), so a host that decorates `routing.loader.attribute` cannot change the bundle routes.
 *
 * @internal
 */
final class ControllerAttributeRouteLoader extends AttributeClassLoader
{
    /**
     * Sets the `_controller` default of a route read from a controller method.
     *
     * @param ReflectionClass<object> $class
     */
    protected function configureRoute(Route $route, ReflectionClass $class, ReflectionMethod $method, object $attr): void
    {
        $route->setDefault('_controller', $class->getName() . '::' . $method->getName());
    }
}
