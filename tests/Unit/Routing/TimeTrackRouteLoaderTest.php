<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Routing;

use Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        self::assertTrue($collection->get('nowo_time_track_index') instanceof \Symfony\Component\Routing\Route);
        self::assertTrue($collection->get('nowo_time_track_api_login') instanceof \Symfony\Component\Routing\Route);
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

    public function testCannotLoadRoutesTwice(): void
    {
        $loader = new TimeTrackRouteLoader(false, [], ['index' => ['path' => '/tools/time-track', 'name' => 'nowo_time_track_index']], '');
        $loader->load('.');

        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }
}
