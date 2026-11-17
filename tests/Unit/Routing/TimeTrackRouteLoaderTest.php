<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Routing;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use Nowo\TimeTrackBundle\Routing\ControllerAttributeRouteLoader;
use Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Routing\Route;

use function ksort;

final class TimeTrackRouteLoaderTest extends TestCase
{
    public function testSupportsNowoTimeTrackType(): void
    {
        $loader = new TimeTrackRouteLoader(true, [], [], '');
        self::assertTrue($loader->supports('.', 'nowo_time_track'));
        self::assertFalse($loader->supports('.', 'annotation'));
    }

    public function testLoadsManageAndApiRoutes(): void
    {
        $loader = new TimeTrackRouteLoader(
            true,
            [
                'login' => ['path' => '/api/time-track/login', 'name' => 'nowo_time_track_api_login'],
            ],
            [
                'index' => ['path' => '/tools/time-track', 'name' => 'nowo_time_track_index'],
            ],
            '',
        );

        $collection = $loader->load('.');
        self::assertTrue($collection->get('nowo_time_track_index') instanceof Route);
        self::assertTrue($collection->get('nowo_time_track_api_login') instanceof Route);
    }

    public function testSkipsApiRoutesWhenClientsDisabled(): void
    {
        $loader = new TimeTrackRouteLoader(
            false,
            [
                'login' => ['path' => '/api/time-track/login', 'name' => 'nowo_time_track_api_login'],
            ],
            [
                'index' => ['path' => '/tools/time-track', 'name' => 'nowo_time_track_index'],
            ],
            '',
        );

        $collection = $loader->load('.');
        self::assertNotNull($collection->get('nowo_time_track_index'));
        self::assertNull($collection->get('nowo_time_track_api_login'));
    }

    public function testCanLoadRoutesAgainInTheSameProcess(): void
    {
        $loader = new TimeTrackRouteLoader(false, [], ['index' => ['path' => '/tools/time-track', 'name' => 'nowo_time_track_index']], '');
        $first  = $loader->load('.');

        $second = $loader->load('.');

        self::assertNotSame($first, $second);
        self::assertSame('/tools/time-track', $second->get('nowo_time_track_index')?->getPath());
    }

    public function testDeviceRoutesOnlyWithTheDeviceFlow(): void
    {
        $api    = ['device_code' => ['path' => '/api/d/code', 'name' => 'api_device_code'], 'device_token' => ['path' => '/api/d/token', 'name' => 'api_device_token'], 'export' => ['path' => '/api/export', 'name' => 'api_export']];
        $manage = ['device_verify' => ['path' => '/device', 'name' => 'device_verify']];

        $off = (new TimeTrackRouteLoader(true, $api, $manage, ''))->load('.');
        self::assertNull($off->get('api_device_code'));
        self::assertNull($off->get('device_verify'));
        self::assertInstanceOf(Route::class, $off->get('api_export'));

        $on = (new TimeTrackRouteLoader(true, $api, $manage, '/p', true, true))->load('.');
        self::assertSame('/p/api/d/code', $on->get('api_device_code')?->getPath());
        self::assertSame(['POST', 'OPTIONS'], $on->get('api_device_token')?->getMethods());
        self::assertSame(['GET', 'POST'], $on->get('device_verify')?->getMethods());

        $hostPage = (new TimeTrackRouteLoader(true, $api, $manage, '', true, false))->load('.');
        self::assertInstanceOf(Route::class, $hostPage->get('api_device_code'));
        self::assertNull($hostPage->get('device_verify'));
    }

    /**
     * REQ-SF-004 (1.28): the default path and name of every `#[Route]` attribute are the configuration defaults of its
     * key, and every attribute route is loaded (none is left out of the loader maps).
     */
    public function testControllerAttributesMatchTheConfigurationDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'clients'    => ['enabled' => true, 'user_provider' => 'app_users'],
            'client_api' => ['device_flow' => ['enabled' => true]],
        ]]);
        /** @var array{routes: array<string, array{path: string, name: string}>, clients: array{routes: array<string, array{path: string, name: string}>}} $config */
        $loaded = (new TimeTrackRouteLoader(true, $config['clients']['routes'], $config['routes'], '', true, true))->load('.');

        $attributes      = [];
        $attributeLoader = new ControllerAttributeRouteLoader();
        foreach (TimeTrackRouteLoader::CONTROLLERS as $controller) {
            foreach ($attributeLoader->load($controller)->all() as $name => $route) {
                $attributes[$name] = $route->getPath();
            }
        }

        $routes = [];
        foreach ($loaded->all() as $name => $route) {
            $routes[$name] = $route->getPath();
        }
        ksort($attributes);
        ksort($routes);

        self::assertCount(38, $routes);
        self::assertSame($attributes, $routes);
        self::assertNotEmpty($loaded->getResources(), 'The controller classes are router cache resources.');
    }

    public function testEveryClientRouteCarriesThePlaceholderRequirements(): void
    {
        $collection = (new TimeTrackRouteLoader(true, ['me' => ['path' => '/me', 'name' => 'me'], 'workday_action' => ['path' => '/w/{action}', 'name' => 'w']], [], '/x'))->load('.');

        $me = $collection->get('me');
        $w  = $collection->get('w');
        self::assertInstanceOf(Route::class, $me);
        self::assertInstanceOf(Route::class, $w);
        self::assertSame(TimeTrackRouteLoader::REQUIREMENTS, $me->getRequirements());
        self::assertSame(TimeTrackRouteLoader::REQUIREMENTS, $w->getRequirements());
        self::assertSame('/x/w/{action}', $w->getPath());
        self::assertSame([], $collection->getAliases());
    }
}
