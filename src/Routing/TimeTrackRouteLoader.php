<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Routing;

use Nowo\TimeTrackBundle\Controller\TimeTrackCalendarApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientDeviceController;
use Nowo\TimeTrackBundle\Controller\TimeTrackDeviceVerificationController;
use Nowo\TimeTrackBundle\Controller\TimeTrackManageController;
use Nowo\TimeTrackBundle\Controller\TimeTrackMeetingsApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackWorkingTimeApiController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader (type `nowo_time_track`) of the manage UI and the client API: configurable paths, names and prefix
 * (documented exemption from attribute routes, see docs/CONFIGURATION.md "Routes").
 */
final class TimeTrackRouteLoader extends Loader
{
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
    }

    /**
     * Builds the manage routes and, when the client API is enabled, the client routes (device flow routes and the
     * default verification page only when the device flow is enabled).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();

        $manageController = TimeTrackManageController::class;
        /** @var array<string, array{0: string, 1: list<string>}> $manageMap */
        $manageMap = [
            'index'   => ['index', ['GET', 'POST']],
            'reports' => ['reports', ['GET']],
        ];

        foreach ($manageMap as $key => [$action, $methods]) {
            if (!isset($this->manageRoutes[$key])) {
                continue;
            }

            $collection->add(
                $this->manageRoutes[$key]['name'],
                $this->createRoute(
                    $this->manageRoutes[$key]['path'],
                    ['_controller' => $manageController . '::' . $action],
                    $methods,
                ),
            );
        }

        if ($this->clientsEnabled && $this->deviceFlowEnabled && $this->deviceDefaultPage && isset($this->manageRoutes['device_verify'])) {
            $collection->add(
                $this->manageRoutes['device_verify']['name'],
                $this->createRoute($this->manageRoutes['device_verify']['path'], ['_controller' => TimeTrackDeviceVerificationController::class . '::verify'], ['GET', 'POST']),
            );
        }

        if ($this->clientsEnabled) {
            $apiController = TimeTrackClientApiController::class;
            /** @var array<string, array{0: string, 1: list<string>, 2?: string}> $apiMap */
            $apiMap = [
                'login'       => ['login', ['POST', 'OPTIONS']],
                'logout'      => ['logout', ['POST', 'OPTIONS']],
                'me'          => ['me', ['GET', 'OPTIONS']],
                'tasks'       => ['tasks', ['GET', 'OPTIONS']],
                'timer'       => ['timer', ['GET', 'OPTIONS']],
                'timer_start' => ['timerStart', ['POST', 'OPTIONS']],
                'timer_stop'  => ['timerStop', ['POST', 'OPTIONS']],
                'heartbeat'   => ['heartbeat', ['POST', 'OPTIONS']],
                'entries'     => ['entries', ['GET', 'OPTIONS']],
                // 1.9 (contract v0.2)
                'timer_switch'       => ['timerSwitch', ['POST', 'OPTIONS']],
                'timer_discard_idle' => ['timerDiscardIdle', ['POST', 'OPTIONS']],
                'export'             => ['export', ['GET', 'OPTIONS']],
            ];
            // Since 1.13 (contract §10.2): the working-time routes (404 while their feature is off).
            $workingTime = TimeTrackWorkingTimeApiController::class;
            $apiMap += [
                'workday'            => ['workday', ['GET', 'OPTIONS'], $workingTime],
                'workday_action'     => ['workdayAction', ['POST', 'OPTIONS'], $workingTime],
                'balance'            => ['balance', ['GET', 'OPTIONS'], $workingTime],
                'timesheets'         => ['timesheet', ['GET', 'OPTIONS'], $workingTime],
                'timesheet_submit'   => ['timesheetSubmit', ['POST', 'OPTIONS'], $workingTime],
                'timesheet_withdraw' => ['timesheetWithdraw', ['POST', 'OPTIONS'], $workingTime],
            ];
            // Since 1.14 (contract §10.3): calendar suggestions and entries without a timer (404 while off).
            $calendar = TimeTrackCalendarApiController::class;
            $apiMap += [
                'calendar_connections'       => ['connections', ['GET', 'OPTIONS'], $calendar],
                'calendar_connection_revoke' => ['connectionRevoke', ['DELETE', 'OPTIONS'], $calendar],
                'suggestions'                => ['suggestions', ['GET', 'OPTIONS'], $calendar],
                'suggestions_accept'         => ['accept', ['POST', 'OPTIONS'], $calendar],
                'suggestions_dismiss'        => ['dismiss', ['POST', 'OPTIONS'], $calendar],
                'suggestions_resolve_keys'   => ['resolveKeys', ['POST', 'OPTIONS'], $calendar],
                // Same path as GET entries: POST only (the GET route answers the preflight).
                'entries_create' => ['createEntry', ['POST'], $calendar],
                // Since 1.21: the billable flag of an own entry.
                'entries_update' => ['updateEntry', ['PATCH', 'OPTIONS'], $calendar],
                // Contract §22: the caller's own meetings (404 while off).
                'me_meetings' => ['meetings', ['GET', 'OPTIONS'], TimeTrackMeetingsApiController::class],
            ];
            if ($this->deviceFlowEnabled) {
                $apiMap['device_code']  = ['code', ['POST', 'OPTIONS'], TimeTrackClientDeviceController::class];
                $apiMap['device_token'] = ['token', ['POST', 'OPTIONS'], TimeTrackClientDeviceController::class];
            }

            foreach ($apiMap as $key => $route) {
                if (!isset($this->clientRoutes[$key])) {
                    continue;
                }

                $collection->add(
                    $this->clientRoutes[$key]['name'],
                    $this->createRoute(
                        $this->clientRoutes[$key]['path'],
                        ['_controller' => ($route[2] ?? $apiController) . '::' . $route[0]],
                        $route[1],
                        self::REQUIREMENTS,
                    ),
                );
            }
        }

        return $collection;
    }

    /** Since 1.13: placeholders of the working-time routes. */
    public const REQUIREMENTS = [
        'action'    => 'clock-in|break-start|break-end|clock-out',
        'periodKey' => 'current|previous|\d{4}-W\d{2}|\d{4}-\d{2}',
        // Since 1.14: a calendar connection id.
        'id' => '[A-Za-z0-9-]{1,36}',
    ];

    /**
     * Whether the resource is the `nowo_time_track` route import.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_time_track';
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     * @param array<string, string> $requirements
     */
    private function createRoute(string $path, array $defaults, array $methods, array $requirements = []): Route
    {
        return new Route($this->routePrefix . $path, $defaults, $requirements, [], '', [], $methods);
    }
}
