<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Routing;

use Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

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
}
