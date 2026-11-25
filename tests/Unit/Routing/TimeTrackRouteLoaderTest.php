<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Routing;

use App\Entity\User;
use LogicException;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use Nowo\TimeTrackBundle\Routing\ControllerAttributeRouteLoader;
use Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function ksort;
use function str_starts_with;

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

    /**
     * Since 1.29 (REQ-API-001): `nowo_time_track_api` loads the client routes only, by both paths.
     */
    public function testTheApiOnlyTypeLoadsTheClientRoutesOnly(): void
    {
        $api    = ['login' => ['path' => '/api/time-track/login', 'name' => 'nowo_time_track_api_login'], 'device_code' => ['path' => '/api/time-track/device/code', 'name' => 'nowo_time_track_api_device_code']];
        $manage = ['index' => ['path' => '/tools/time-track', 'name' => 'nowo_time_track_index'], 'device_verify' => ['path' => '/device', 'name' => 'device_verify']];
        $loader = new TimeTrackRouteLoader(true, $api, $manage, '', true, true);

        self::assertTrue($loader->supports('.', TimeTrackRouteLoader::API_ONLY_TYPE));
        $collection = $loader->load('.', TimeTrackRouteLoader::API_ONLY_TYPE);
        self::assertSame(['nowo_time_track_api_login', 'nowo_time_track_api_device_code', 'nowo_time_track_api_v1_login', 'nowo_time_track_api_v1_device_code'], array_keys($collection->all()));

        self::assertSame([], (new TimeTrackRouteLoader(false, $api, $manage, ''))->load('.', TimeTrackRouteLoader::API_ONLY_TYPE)->all());
    }

    /**
     * Since 1.29: the v1 twin keeps the route attribute (controller, methods, requirements) and takes its path from the
     * attribute default, whatever the unversioned path or name was configured to; the route prefix applies to both.
     */
    public function testEveryClientRouteGetsAVersionedTwin(): void
    {
        $api        = ['workday_action' => ['path' => '/legacy/day/{action}', 'name' => 'acme_day_action']];
        $collection = (new TimeTrackRouteLoader(true, $api, [], '/t', false, false, '/edge'))->load('.');

        $legacy = $collection->get('acme_day_action');
        $v1     = $collection->get('nowo_time_track_api_v1_workday_action');
        self::assertInstanceOf(Route::class, $legacy);
        self::assertInstanceOf(Route::class, $v1);
        self::assertSame('/t/legacy/day/{action}', $legacy->getPath());
        self::assertSame('/t/edge/time-track/v1/workday/{action}', $v1->getPath());
        self::assertSame($legacy->getDefaults(), $v1->getDefaults());
        self::assertSame($legacy->getRequirements(), $v1->getRequirements());
        self::assertSame($legacy->getMethods(), $v1->getMethods());
    }

    /**
     * Since 1.30 (client_api.routes.include): a route that is not listed does not exist, on the unversioned path and on
     * the v1 twin alike; the default (empty) publishes all of them.
     */
    public function testIncludeLimitsTheClientRoutesOnBothPrefixes(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'clients' => ['enabled' => true]]]);
        /* @var array{routes: array<string, array{path: string, name: string}>, clients: array{routes: array<string, array{path: string, name: string}>}} $config */
        $include = ['login', 'me', 'timer_start'];

        foreach (['nowo_time_track', TimeTrackRouteLoader::API_ONLY_TYPE] as $type) {
            $loader = new TimeTrackRouteLoader(true, $config['clients']['routes'], $config['routes'], '', true, false, '/api', $include);
            $names  = array_keys($loader->load('.', $type)->all());
            $client = array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'nowo_time_track_api')));

            self::assertSame(
                ['nowo_time_track_api_login', 'nowo_time_track_api_me', 'nowo_time_track_api_timer_start', 'nowo_time_track_api_v1_login', 'nowo_time_track_api_v1_me', 'nowo_time_track_api_v1_timer_start'],
                $client,
                $type,
            );
            foreach (['balance', 'calendar_connections', 'entries_create', 'entries_update', 'suggestions', 'timesheets', 'device_code'] as $excluded) {
                self::assertNotContains('nowo_time_track_api_' . $excluded, $names);
                self::assertNotContains(TimeTrackRouteLoader::V1_NAME_PREFIX . $excluded, $names);
            }
        }

        // The router has no such route: a path match fails (404), under both prefixes.
        $collection = (new TimeTrackRouteLoader(true, $config['clients']['routes'], $config['routes'], '', true, false, '/api', $include))->load('.', TimeTrackRouteLoader::API_ONLY_TYPE);
        $paths      = array_map(static fn (Route $r): string => $r->getPath(), $collection->all());
        self::assertContains('/api/time-track/login', $paths);
        self::assertContains('/api/time-track/v1/login', $paths);
        self::assertNotContains('/api/time-track/balance', $paths);
        self::assertNotContains('/api/time-track/v1/balance', $paths);

        $all = (new TimeTrackRouteLoader(true, $config['clients']['routes'], $config['routes'], '', true, false))->load('.', TimeTrackRouteLoader::API_ONLY_TYPE);
        self::assertGreaterThan(count($include) * 2, count($all->all()));
        self::assertTrue($all->get('nowo_time_track_api_balance') instanceof Route && $all->get('nowo_time_track_api_v1_balance') instanceof Route);
    }

    /**
     * Audit SR-TT-L1/L2: an include list may not leave out a route that a listed route depends on.
     *
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function missingDependencies(): iterable
    {
        yield 'login without logout' => [['login', 'me'], 'logout'];
        yield 'device_token without logout' => [['device_token'], 'logout'];
        yield 'upload without delete' => [['monitoring_upload', 'monitoring_acknowledge'], 'monitoring_screenshot_delete'];
        yield 'intent without acknowledge' => [['monitoring_screenshot_intent', 'monitoring_screenshot_delete'], 'monitoring_acknowledge'];
        yield 'calendar without revoke' => [['calendar_connections'], 'calendar_connection_revoke'];
        yield 'timer_start without timer_stop' => [['timer_start'], 'timer_stop'];
        yield 'timer_switch without timer_stop' => [['timer_switch'], 'timer_stop'];
        yield 'activity without acknowledge' => [['monitoring_activity'], 'monitoring_acknowledge'];
        yield 'timesheet_submit without withdraw' => [['timesheet_submit'], 'timesheet_withdraw'];
        yield 'entries_create without entries' => [['entries_create'], 'entries'];
    }

    /**
     * @param list<string> $include
     */
    #[DataProvider('missingDependencies')]
    public function testAnIncludeListMustKeepTheRoutesItDependsOn(array $include, string $missing): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('but not "' . $missing . '"');
        TimeTrackRouteLoader::assertIncludeDependencies($include);
    }

    public function testCompleteIncludeListsAreAccepted(): void
    {
        TimeTrackRouteLoader::assertIncludeDependencies([]);
        TimeTrackRouteLoader::assertIncludeDependencies(['timer_start', 'timer_switch', 'timer_stop', 'monitoring_activity', 'timesheet_submit', 'timesheet_withdraw', 'login', 'logout', 'me', 'entries', 'entries_create', 'calendar_connections', 'calendar_connection_revoke', 'monitoring_upload', 'monitoring_screenshot_delete', 'monitoring_acknowledge']);
        self::assertSame([], array_diff(array_merge(...array_values(TimeTrackRouteLoader::INCLUDE_DEPENDENCIES)), array_keys(TimeTrackRouteLoader::clientRouteMap())));
        self::assertSame([], array_diff(array_keys(TimeTrackRouteLoader::INCLUDE_DEPENDENCIES), array_keys(TimeTrackRouteLoader::clientRouteMap())));
    }

    public function testAnEmptyIncludeListPublishesEveryClientRoute(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'clients' => ['enabled' => true]]]);
        /* @var array{routes: array<string, array{path: string, name: string}>, clients: array{routes: array<string, array{path: string, name: string}>}} $config */
        self::assertSame([], $config['client_api']['routes']['include']);
        $default  = (new TimeTrackRouteLoader(true, $config['clients']['routes'], $config['routes'], '', true, true))->load('.');
        $explicit = (new TimeTrackRouteLoader(true, $config['clients']['routes'], $config['routes'], '', true, true, '/api', []))->load('.');
        self::assertSame(array_keys($default->all()), array_keys($explicit->all()));
    }

    /**
     * Audit SO probe P4: importing both types (or any `client_api.prefix`) never yields two routes for one method and
     * path; the second import only overwrites routes of the same name.
     */
    public function testBothImportsAndAnyPrefixNeverDuplicateAMethodAndPath(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'clients' => ['enabled' => true]]]);
        /* @var array{routes: array<string, array{path: string, name: string}>, clients: array{routes: array<string, array{path: string, name: string}>}} $config */
        foreach (['/api', '', '/x', '/api/time-track'] as $prefix) {
            $loader = new TimeTrackRouteLoader(true, $config['clients']['routes'], $config['routes'], '', true, true, $prefix);
            $all    = new RouteCollection();
            $all->addCollection($loader->load('.', 'nowo_time_track'));
            $count = count($all->all());
            $all->addCollection($loader->load('.', TimeTrackRouteLoader::API_ONLY_TYPE));

            $seen = [];
            foreach ($all->all() as $name => $route) {
                foreach ($route->getMethods() as $method) {
                    $key = $method . ' ' . $route->getPath();
                    self::assertArrayNotHasKey($key, $seen, $prefix . ': ' . $name);
                    $seen[$key] = $name;
                }
            }
            self::assertCount($count, $all->all(), $prefix);
        }
    }

    public function testVersionedPath(): void
    {
        self::assertSame('/api/time-track/v1/me/meetings', TimeTrackRouteLoader::versionedPath('/api', '/api/time-track/me/meetings'));
        self::assertSame('/time-track/v1/login', TimeTrackRouteLoader::versionedPath('', '/api/time-track/login'));
        // A path outside the unversioned base is appended whole.
        self::assertSame('/api/time-track/v1/other', TimeTrackRouteLoader::versionedPath('/api', '/other'));
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

        $routes    = [];
        $versioned = [];
        foreach ($loaded->all() as $name => $route) {
            if (str_starts_with($name, TimeTrackRouteLoader::V1_NAME_PREFIX)) {
                // Since 1.29: the v1 twin of every client route (its path derives from the attribute default).
                $versioned[$name] = $route->getPath();
                continue;
            }
            $routes[$name] = $route->getPath();
        }
        ksort($attributes);
        ksort($routes);

        self::assertCount(40, $routes);
        self::assertSame($attributes, $routes);
        self::assertCount(37, $versioned);
        foreach ($versioned as $path) {
            self::assertStringStartsWith('/api/time-track/v1/', $path);
        }
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
