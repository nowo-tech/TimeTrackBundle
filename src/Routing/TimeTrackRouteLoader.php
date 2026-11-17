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

use function sprintf;

/**
 * Route loader (type `nowo_time_track`) of the manage UI and the client API. Since 1.28 (REQ-SF-004) the routes are
 * declared with `#[Route]` attributes on the controllers (methods, requirements, `_controller`, default path and name);
 * this loader reads them and applies the configuration: the path and name of each route (`routes.*`,
 * `clients.routes.*`), `route_prefix` (prepended to every path as is) and the feature switches (client API, device
 * flow, default verification page). The route collection is the 1.27 one, route for route and in the same order
 * (pinned by tests/Fixtures/Routing/route-collections.json); hosts keep their `type: nowo_time_track` import.
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
     */
    public function __construct(
        private readonly bool $clientsEnabled,
        private readonly array $clientRoutes,
        private readonly array $manageRoutes,
        private readonly string $routePrefix,
        private readonly bool $deviceFlowEnabled = false,
        private readonly bool $deviceDefaultPage = false,
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

        $this->addRoutes($collection, $attributes, $this->manageRoutes, self::MANAGE_ROUTES);

        if ($this->clientsEnabled && $this->deviceFlowEnabled && $this->deviceDefaultPage) {
            $this->addRoutes($collection, $attributes, $this->manageRoutes, ['device_verify' => self::DEVICE_VERIFY_ROUTE]);
        }

        if ($this->clientsEnabled) {
            $this->addRoutes($collection, $attributes, $this->clientRoutes, $this->deviceFlowEnabled ? self::CLIENT_ROUTES + self::DEVICE_ROUTES : self::CLIENT_ROUTES);
        }

        return $collection;
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
     * Whether the resource is the `nowo_time_track` route import.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_time_track';
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
