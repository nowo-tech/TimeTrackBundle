<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Routing;

use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackManageController;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class TimeTrackRouteLoader extends Loader
{
    private bool $loaded = false;

    /**
     * @param array<string, array{path: string, name: string}> $clientRoutes
     * @param array<string, array{path: string, name: string}> $manageRoutes
     */
    public function __construct(
        private readonly bool $clientsEnabled,
        private readonly array $clientRoutes,
        private readonly array $manageRoutes,
        private readonly string $routePrefix,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('TimeTrack routes already loaded.');
        }

        $this->loaded = true;
        $collection   = new RouteCollection();

        $manageController = TimeTrackManageController::class;
        /** @var array<string, array{0: string, 1: list<string>}> $manageMap */
        $manageMap = [
            'index'   => ['index', ['GET']],
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

        if ($this->clientsEnabled) {
            $apiController = TimeTrackClientApiController::class;
            /** @var array<string, array{0: string, 1: list<string>}> $apiMap */
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
            ];

            foreach ($apiMap as $key => [$action, $methods]) {
                if (!isset($this->clientRoutes[$key])) {
                    continue;
                }

                $collection->add(
                    $this->clientRoutes[$key]['name'],
                    $this->createRoute(
                        $this->clientRoutes[$key]['path'],
                        ['_controller' => $apiController . '::' . $action],
                        $methods,
                    ),
                );
            }
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_time_track';
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     */
    private function createRoute(string $path, array $defaults, array $methods, array $requirements = []): Route
    {
        return new Route($this->routePrefix . $path, $defaults, $requirements, [], '', [], $methods);
    }
}
