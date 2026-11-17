<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration\Routing;

use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\HostRoutingKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * REQ-SF-004 (attribute routes, 1.28): the route collection the hosts get through the `type: nowo_time_track` import
 * is pinned by `tests/Fixtures/Routing/route-collections.json`, dumped from the 1.27 route loader (routes built in PHP)
 * before the routes moved to `#[Route]` attributes. Every route keeps its name, order, path, methods, requirements,
 * defaults, options, host, schemes and condition, with the default configuration and with custom prefixes, paths and
 * names. `TIME_TRACK_ROUTE_FIXTURE_WRITE=1` rewrites the fixture (only to record an intended change).
 */
final class RouteCollectionFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Routing/route-collections.json';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo_time_track_route_fixture_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function scenarios(): iterable
    {
        foreach (self::configs() as $name => $config) {
            yield $name => [$name];
        }
        yield 'functional_kernel' => ['functional_kernel'];
    }

    #[DataProvider('scenarios')]
    public function testRouteCollectionMatchesTheFixture(string $scenario): void
    {
        $actual = self::export($this->routes($scenario));

        if (getenv('TIME_TRACK_ROUTE_FIXTURE_WRITE') === '1') {
            $all            = self::fixture();
            $all[$scenario] = $actual;
            file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }

        $expected = self::fixture()[$scenario] ?? null;
        self::assertIsArray($expected, 'No fixture for scenario ' . $scenario);
        self::assertSame($expected, $actual);
    }

    /**
     * The `nowo_time_track` configuration of each host-kernel scenario.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function configs(): array
    {
        $clients = ['enabled' => true, 'user_provider' => 'security.user.provider.concrete.in_memory'];

        return [
            // Clients disabled: the manage UI routes only.
            'default' => [],
            'clients_enabled' => ['clients' => $clients],
            'clients_device_flow' => [
                'clients'    => $clients,
                'client_api' => ['device_flow' => ['enabled' => true]],
            ],
            // A route prefix, renamed and moved routes, the device flow without the default page.
            'custom_prefix' => [
                'route_prefix' => '/acme',
                'routes'       => [
                    'index'         => ['path' => '/time', 'name' => 'acme_time_index'],
                    'reports'       => ['path' => '/time/reports', 'name' => 'acme_time_reports'],
                    'device_verify' => ['path' => '/time/device', 'name' => 'acme_time_device'],
                ],
                'clients' => $clients + ['routes' => [
                    'login'          => ['path' => '/api/v9/session', 'name' => 'acme_api_login'],
                    'entries'        => ['path' => '/api/v9/entries', 'name' => 'acme_api_entries'],
                    'entries_create' => ['path' => '/api/v9/entries', 'name' => 'acme_api_entries_create'],
                    'workday_action' => ['path' => '/api/v9/day/{action}', 'name' => 'acme_api_day_action'],
                    'device_token'   => ['path' => '/api/v9/device/token', 'name' => 'acme_api_device_token'],
                ]],
                'client_api' => ['device_flow' => [
                    'enabled'          => true,
                    'default_page'     => false,
                    'verification_uri' => 'https://time.example.test/device',
                ]],
            ],
            // A prefix with a trailing slash is concatenated as is (no normalisation).
            'trailing_slash_prefix' => [
                'route_prefix' => '/tenant/',
                'clients'      => $clients,
                'client_api'   => ['device_flow' => ['enabled' => true]],
            ],
        ];
    }

    private function routes(string $scenario): RouteCollection
    {
        $kernel = $scenario === 'functional_kernel'
            ? new FunctionalKernel()
            : new HostRoutingKernel($this->dir . '/' . $scenario, true, self::configs()[$scenario]);
        $kernel->boot();

        try {
            return self::router($kernel)->getRouteCollection();
        } finally {
            $kernel->shutdown();
        }
    }

    private static function router(KernelInterface $kernel): RouterInterface
    {
        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }

    /**
     * The bundle routes (by `_controller`) in collection order, with every attribute of each route, and the aliases.
     *
     * @return array{routes: list<array<string, mixed>>, aliases: array<string, string>}
     */
    private static function export(RouteCollection $collection): array
    {
        $routes = [];
        foreach ($collection->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || !str_starts_with($controller, 'Nowo\\TimeTrackBundle\\')) {
                continue;
            }
            $routes[] = [
                'name'         => $name,
                'path'         => $route->getPath(),
                'host'         => $route->getHost(),
                'schemes'      => $route->getSchemes(),
                'methods'      => $route->getMethods(),
                'defaults'     => $route->getDefaults(),
                'requirements' => $route->getRequirements(),
                'options'      => $route->getOptions(),
                'condition'    => $route->getCondition(),
            ];
        }

        $aliases = [];
        foreach ($collection->getAliases() as $name => $alias) {
            if (str_starts_with($name, 'Nowo\\TimeTrackBundle\\') || str_starts_with($alias->getId(), 'nowo_time_track')) {
                $aliases[$name] = $alias->getId();
            }
        }

        return ['routes' => $routes, 'aliases' => $aliases];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $json = @file_get_contents(self::FIXTURE);
        if (!is_string($json)) {
            return [];
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
