<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Routing;

use LogicException;
use Nowo\TimeTrackBundle\Controller\TimeTrackCalendarApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientDeviceController;
use Nowo\TimeTrackBundle\Controller\TimeTrackDeviceVerificationController;
use Nowo\TimeTrackBundle\Controller\TimeTrackManageController;
use Nowo\TimeTrackBundle\Controller\TimeTrackMeetingsApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackMonitoringApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackWorkingTimeApiController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function array_flip;
use function array_intersect;
use function array_intersect_key;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Route loader (type `nowo_time_track`) of the manage UI and the client API. Since 1.28 (REQ-SF-004) the routes are
 * declared with `#[Route]` attributes on the controllers (methods, requirements, `_controller`, default path and name);
 * this loader reads them and applies the configuration: the path and name of each route (`routes.*`,
 * `clients.routes.*`), `route_prefix` (prepended to every path as is) and the feature switches (client API, device
 * flow, default verification page). The route collection is the 1.27 one, route for route and in the same order
 * (pinned by tests/Fixtures/Routing/route-collections.json); hosts keep their `type: nowo_time_track` import.
 *
 * Since 1.29 (REQ-API-001, house rule `<prefix>/<segment>/v<N>`): every client API route is also served under
 * `<route_prefix><client_api.prefix>/time-track/v1/…` (default `/api/time-track/v1/…`), named
 * `nowo_time_track_api_v1_<key>`, after the unversioned routes. The unversioned paths and names stay as **permanent**
 * aliases (installed desktop and extension clients call them): same controller, defaults, requirements and methods,
 * so the same auth, rate limits, CORS rules and response. The type `nowo_time_track_api` loads the client API routes
 * only (unversioned and v1), without the manage UI or the device verification page.
 */
final class TimeTrackRouteLoader extends Loader
{
    /**
     * Manage UI routes in collection order: configuration key => controller action.
     */
    private const MANAGE_ROUTES = [
        'index'   => TimeTrackManageController::class . '::index',
        'reports' => TimeTrackManageController::class . '::reports',
    ];

    /** The default device verification page (client API, device flow and default page enabled). */
    private const DEVICE_VERIFY_ROUTE = TimeTrackDeviceVerificationController::class . '::verify';

    /**
     * Client API routes in collection order: configuration key => controller action.
     */
    private const CLIENT_ROUTES = [
        'login'       => TimeTrackClientApiController::class . '::login',
        'logout'      => TimeTrackClientApiController::class . '::logout',
        'me'          => TimeTrackClientApiController::class . '::me',
        'tasks'       => TimeTrackClientApiController::class . '::tasks',
        'timer'       => TimeTrackClientApiController::class . '::timer',
        'timer_start' => TimeTrackClientApiController::class . '::timerStart',
        'timer_stop'  => TimeTrackClientApiController::class . '::timerStop',
        'heartbeat'   => TimeTrackClientApiController::class . '::heartbeat',
        'entries'     => TimeTrackClientApiController::class . '::entries',
        // 1.9 (contract v0.2)
        'timer_switch'       => TimeTrackClientApiController::class . '::timerSwitch',
        'timer_discard_idle' => TimeTrackClientApiController::class . '::timerDiscardIdle',
        'export'             => TimeTrackClientApiController::class . '::export',
        // Since 1.13 (contract §10.2): the working-time routes (404 while their feature is off).
        'workday'            => TimeTrackWorkingTimeApiController::class . '::workday',
        'workday_action'     => TimeTrackWorkingTimeApiController::class . '::workdayAction',
        'balance'            => TimeTrackWorkingTimeApiController::class . '::balance',
        'timesheets'         => TimeTrackWorkingTimeApiController::class . '::timesheet',
        'timesheet_submit'   => TimeTrackWorkingTimeApiController::class . '::timesheetSubmit',
        'timesheet_withdraw' => TimeTrackWorkingTimeApiController::class . '::timesheetWithdraw',
        // Since 1.14 (contract §10.3): calendar suggestions and entries without a timer (404 while off).
        'calendar_connections'       => TimeTrackCalendarApiController::class . '::connections',
        'calendar_connection_revoke' => TimeTrackCalendarApiController::class . '::connectionRevoke',
        'suggestions'                => TimeTrackCalendarApiController::class . '::suggestions',
        'suggestions_accept'         => TimeTrackCalendarApiController::class . '::accept',
        'suggestions_dismiss'        => TimeTrackCalendarApiController::class . '::dismiss',
        'suggestions_resolve_keys'   => TimeTrackCalendarApiController::class . '::resolveKeys',
        // Same path as GET entries: POST only (the GET route answers the preflight).
        'entries_create' => TimeTrackCalendarApiController::class . '::createEntry',
        // Since 1.21: the billable flag of an own entry.
        'entries_update' => TimeTrackCalendarApiController::class . '::updateEntry',
        // Contract §22: the caller's own meetings (404 while off).
        'me_meetings' => TimeTrackMeetingsApiController::class . '::meetings',
        // Since 1.26 (contract §24, spec 199): activity monitoring (404 while monitoring is off).
        'monitoring_acknowledge'          => TimeTrackMonitoringApiController::class . '::acknowledge',
        'monitoring_screenshot_intent'    => TimeTrackMonitoringApiController::class . '::screenshotIntent',
        'monitoring_upload'               => TimeTrackMonitoringApiController::class . '::upload',
        'monitoring_screenshot_discarded' => TimeTrackMonitoringApiController::class . '::screenshotDiscarded',
        'monitoring_screenshot_delete'    => TimeTrackMonitoringApiController::class . '::screenshotDelete',
        'monitoring_activity'             => TimeTrackMonitoringApiController::class . '::activity',
    ];

    /** Device flow routes of the client API (client_api.device_flow.enabled), after the other client routes. */
    private const DEVICE_ROUTES = [
        'device_code'  => TimeTrackClientDeviceController::class . '::code',
        'device_token' => TimeTrackClientDeviceController::class . '::token',
    ];

    /** Since 1.29: the route type of the client API only (unversioned + v1 routes, no manage UI). */
    public const API_ONLY_TYPE = 'nowo_time_track_api';

    /** Since 1.29: the path segment of the client API after the host API prefix. */
    public const API_SEGMENT = '/time-track';

    /** Since 1.29: the current client API version in the path. */
    public const API_VERSION = 'v1';

    /** Since 1.29: name prefix of the versioned routes (`nowo_time_track_api_v1_<key>`). */
    public const V1_NAME_PREFIX = 'nowo_time_track_api_v1_';

    /** The default (attribute) path base of the unversioned client routes; the v1 path keeps what follows it. */
    private const LEGACY_PATH_BASE = '/api/time-track';

    /** The controllers whose `#[Route]` attributes are read. */
    public const CONTROLLERS = [
        TimeTrackManageController::class,
        TimeTrackDeviceVerificationController::class,
        TimeTrackClientApiController::class,
        TimeTrackWorkingTimeApiController::class,
        TimeTrackCalendarApiController::class,
        TimeTrackMeetingsApiController::class,
        TimeTrackMonitoringApiController::class,
        TimeTrackClientDeviceController::class,
    ];

    private readonly ControllerAttributeRouteLoader $attributeLoader;

    /**
     * @param array<string, array{path: string, name: string}> $clientRoutes
     * @param array<string, array{path: string, name: string}> $manageRoutes
     * @param bool $deviceFlowEnabled Route `device/code` and `device/token` (client_api.device_flow.enabled)
     * @param bool $deviceDefaultPage Route the default device verification page (client_api.device_flow.default_page)
     * @param string $apiPrefix Host API prefix of the versioned routes (client_api.prefix, default `/api`)
     * @param list<string> $includeClientRoutes Since 1.30 (client_api.routes.include): the client route keys to publish, on the unversioned and the v1 paths; empty = all
     */
    public function __construct(
        private readonly bool $clientsEnabled,
        private readonly array $clientRoutes,
        private readonly array $manageRoutes,
        private readonly string $routePrefix,
        private readonly bool $deviceFlowEnabled = false,
        private readonly bool $deviceDefaultPage = false,
        private readonly string $apiPrefix = '/api',
        private readonly array $includeClientRoutes = [],
    ) {
        parent::__construct();
        $this->attributeLoader = new ControllerAttributeRouteLoader();
    }

    /**
     * Builds the manage routes and, when the client API is enabled, the client routes (device flow routes and the
     * default verification page only when the device flow is enabled), from the controller attributes.
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();
        $attributes = $this->attributeRoutes($collection);
        $apiOnly    = $type === self::API_ONLY_TYPE;

        if (!$apiOnly) {
            $this->addRoutes($collection, $attributes, $this->manageRoutes, self::MANAGE_ROUTES);
        }

        if (!$apiOnly && $this->clientsEnabled && $this->deviceFlowEnabled && $this->deviceDefaultPage) {
            $this->addRoutes($collection, $attributes, $this->manageRoutes, ['device_verify' => self::DEVICE_VERIFY_ROUTE]);
        }

        if ($this->clientsEnabled) {
            $clientMap = self::clientRouteMap($this->deviceFlowEnabled);
            if ($this->includeClientRoutes !== []) {
                // Since 1.30: a route that is not listed does not exist (the router answers 404), on both paths.
                $clientMap = array_intersect_key($clientMap, array_flip($this->includeClientRoutes));
            }
            $this->addRoutes($collection, $attributes, $this->clientRoutes, $clientMap);
            $this->addRoutes($collection, $attributes, $this->versionedRoutes($attributes, $clientMap), $clientMap);
        }

        return $collection;
    }

    /**
     * Since 1.30: the client route keys that exist (the device flow routes only with the device flow); the valid values
     * of `client_api.routes.include`.
     *
     * @return array<string, string> key => controller action
     */
    public static function clientRouteMap(bool $deviceFlowEnabled = true): array
    {
        return $deviceFlowEnabled ? self::CLIENT_ROUTES + self::DEVICE_ROUTES : self::CLIENT_ROUTES;
    }

    /**
     * Since 1.29 (audit SO-I3, called at container build): fails when a configured unversioned client route takes the
     * name of a v1 route, or a v1 path with an overlapping method (the first registered route would win silently).
     *
     * @param array<string, array{path: string, name: string}> $clientRoutes `clients.routes`
     */
    public static function assertNoVersionedCollision(array $clientRoutes, string $apiPrefix, bool $deviceFlowEnabled): void
    {
        $attributes = [];
        $loader     = new ControllerAttributeRouteLoader();
        foreach (self::CONTROLLERS as $controller) {
            foreach ($loader->load($controller)->all() as $route) {
                $attributes[(string) $route->getDefault('_controller')] = $route;
            }
        }

        $routes = [];
        foreach ($deviceFlowEnabled ? self::CLIENT_ROUTES + self::DEVICE_ROUTES : self::CLIENT_ROUTES as $key => $controller) {
            if (isset($clientRoutes[$key], $attributes[$controller])) {
                $routes[$key] = [$clientRoutes[$key], $attributes[$controller]];
            }
        }

        foreach ($routes as $key => [$legacy, $attribute]) {
            foreach ($routes as $v1Key => [, $v1Attribute]) {
                $name = self::V1_NAME_PREFIX . $v1Key;
                if ($legacy['name'] === $name) {
                    throw new LogicException(sprintf('nowo_time_track.clients.routes.%s.name "%s" is the name of the v1 route of "%s"; choose another name.', $key, $name, $v1Key));
                }
                $path = self::versionedPath($apiPrefix, $v1Attribute->getPath());
                if ($legacy['path'] === $path && array_intersect($attribute->getMethods(), $v1Attribute->getMethods()) !== []) {
                    throw new LogicException(sprintf('nowo_time_track.clients.routes.%s.path "%s" is the path of the v1 route "%s"; choose another path.', $key, $path, $name));
                }
            }
        }
    }

    /**
     * Since 1.29: the versioned path of a client route (`<route_prefix><client_api.prefix>/time-track/v1<suffix>`, the
     * suffix being the default path after `/api/time-track`), without the route prefix.
     */
    public static function versionedPath(string $apiPrefix, string $defaultPath): string
    {
        $suffix = str_starts_with($defaultPath, self::LEGACY_PATH_BASE . '/') ? substr($defaultPath, strlen(self::LEGACY_PATH_BASE)) : $defaultPath;

        return $apiPrefix . self::API_SEGMENT . '/' . self::API_VERSION . $suffix;
    }

    /**
     * The name and path (without the route prefix) of the v1 route of every key of `$map` that is routed unversioned;
     * the per-route `path` and `name` options configure the unversioned routes only.
     *
     * @param array<string, Route> $attributes
     * @param array<string, string> $map
     *
     * @return array<string, array{path: string, name: string}>
     */
    private function versionedRoutes(array $attributes, array $map): array
    {
        $routes = [];
        foreach ($map as $key => $controller) {
            if (!isset($this->clientRoutes[$key])) {
                continue;
            }
            $attribute    = $attributes[$controller] ?? throw new LogicException(sprintf('No #[Route] attribute on "%s".', $controller));
            $routes[$key] = ['path' => self::versionedPath($this->apiPrefix, $attribute->getPath()), 'name' => self::V1_NAME_PREFIX . $key];
        }

        return $routes;
    }

    /** Since 1.13: placeholders of the working-time routes. */
    public const REQUIREMENTS = [
        'action'    => 'clock-in|break-start|break-end|clock-out',
        'periodKey' => 'current|previous|\d{4}-W\d{2}|\d{4}-\d{2}',
        // Since 1.14: a calendar connection id.
        'id' => '[A-Za-z0-9-]{1,36}',
        // Since 1.26: a screenshot upload id (UUID) and a host screenshot id.
        'uploadId'     => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'screenshotId' => '[A-Za-z0-9_-]{1,64}',
    ];

    /**
     * Whether the resource is the `nowo_time_track` route import (or, since 1.29, the API-only `nowo_time_track_api`).
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_time_track' || $type === self::API_ONLY_TYPE;
    }

    /**
     * The routes declared by the controller attributes, by `_controller`; the controller class resources go to
     * `$collection` (router cache freshness in debug).
     *
     * @return array<string, Route>
     */
    private function attributeRoutes(RouteCollection $collection): array
    {
        $routes = [];
        foreach (self::CONTROLLERS as $controller) {
            $loaded = $this->attributeLoader->load($controller);
            foreach ($loaded->getResources() as $resource) {
                $collection->addResource($resource);
            }
            foreach ($loaded->all() as $route) {
                $routes[(string) $route->getDefault('_controller')] = $route;
            }
        }

        return $routes;
    }

    /**
     * Adds the configured routes of `$map` (configuration key => controller action) in map order: configured name
     * and path (with the route prefix), everything else from the controller attribute. Keys without a configuration
     * entry are skipped.
     *
     * @param array<string, Route> $attributes
     * @param array<string, array{path: string, name: string}> $config
     * @param array<string, string> $map
     */
    private function addRoutes(RouteCollection $collection, array $attributes, array $config, array $map): void
    {
        foreach ($map as $key => $controller) {
            if (!isset($config[$key])) {
                continue;
            }
            $attribute = $attributes[$controller] ?? throw new LogicException(sprintf('No #[Route] attribute on "%s".', $controller));

            $collection->add($config[$key]['name'], new Route(
                $this->routePrefix . $config[$key]['path'],
                $attribute->getDefaults(),
                $attribute->getRequirements(),
                $attribute->getOptions(),
                $attribute->getHost(),
                $attribute->getSchemes(),
                $attribute->getMethods(),
                $attribute->getCondition(),
            ));
        }
    }
}
