<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Routing;

use Nowo\TimeTrackBundle\Tests\Support\HostRoutingKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-SF-004 rule 7: the bundle routes are opt-in. A host whose routing only holds the Symfony default
 * `routing.controllers` resource must not get any bundle route; they exist only where the host imports the
 * `type: nowo_time_track` route loader.
 */
final class HostRoutingControllersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo_time_track_routing_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRoutingControllersResourceDoesNotLoadTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $bundleRoutes = [];
        foreach ($this->routes(false)->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (str_starts_with($name, 'nowo_time_track') || (is_string($controller) && str_starts_with($controller, 'Nowo\\TimeTrackBundle\\'))) {
                $bundleRoutes[] = $name;
            }
        }

        self::assertSame([], $bundleRoutes, 'The bundle routes must only exist when the host imports them.');
    }

    public function testImportedRouteLoaderRegistersTheBundleRoutes(): void
    {
        $names = [];
        foreach ($this->routes(true)->all() as $name => $route) {
            if (str_starts_with($name, 'nowo_time_track')) {
                $names[] = $name;
            }
        }

        self::assertNotSame([], $names);
    }

    private function routes(bool $importBundleRoutes): RouteCollection
    {
        $kernel = new HostRoutingKernel($this->dir . ($importBundleRoutes ? '/imported' : '/controllers-only'), $importBundleRoutes);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            return $router->getRouteCollection();
        } finally {
            $kernel->shutdown();
        }
    }
}
