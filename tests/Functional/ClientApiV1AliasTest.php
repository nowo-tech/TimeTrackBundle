<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

use function array_keys;
use function explode;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function sort;
use function str_replace;
use function str_starts_with;

/**
 * Since 1.29 (REQ-API-001): every client API route is served under `/api/time-track/v1/…` and keeps its unversioned
 * path as a permanent alias (installed desktop and extension clients call it). The route table below is the
 * `debug:router` output of the functional kernel (device flow on): 35 client routes, each by both paths. The test pins
 * the table against the router, checks that each pair is the same route (controller, methods, requirements,
 * defaults, options), then calls every route by both paths — CORS preflight, without a token and with a token — and
 * compares status, headers and body.
 */
final class ClientApiV1AliasTest extends FunctionalTestCase
{
    /**
     * Legacy name, legacy path, v1 name, v1 path, methods.
     *
     * @var list<array{string, string, string, string, string}>
     */
    private const ROUTES = [
        ['nowo_time_track_api_login', '/api/time-track/login', 'nowo_time_track_api_v1_login', '/api/time-track/v1/login', 'POST|OPTIONS'],
        ['nowo_time_track_api_logout', '/api/time-track/logout', 'nowo_time_track_api_v1_logout', '/api/time-track/v1/logout', 'POST|OPTIONS'],
        ['nowo_time_track_api_me', '/api/time-track/me', 'nowo_time_track_api_v1_me', '/api/time-track/v1/me', 'GET|OPTIONS'],
        ['nowo_time_track_api_tasks', '/api/time-track/tasks', 'nowo_time_track_api_v1_tasks', '/api/time-track/v1/tasks', 'GET|OPTIONS'],
        ['nowo_time_track_api_timer', '/api/time-track/timer', 'nowo_time_track_api_v1_timer', '/api/time-track/v1/timer', 'GET|OPTIONS'],
        ['nowo_time_track_api_timer_start', '/api/time-track/timer/start', 'nowo_time_track_api_v1_timer_start', '/api/time-track/v1/timer/start', 'POST|OPTIONS'],
        ['nowo_time_track_api_timer_stop', '/api/time-track/timer/stop', 'nowo_time_track_api_v1_timer_stop', '/api/time-track/v1/timer/stop', 'POST|OPTIONS'],
        ['nowo_time_track_api_heartbeat', '/api/time-track/heartbeat', 'nowo_time_track_api_v1_heartbeat', '/api/time-track/v1/heartbeat', 'POST|OPTIONS'],
        ['nowo_time_track_api_entries', '/api/time-track/entries', 'nowo_time_track_api_v1_entries', '/api/time-track/v1/entries', 'GET|OPTIONS'],
        ['nowo_time_track_api_timer_switch', '/api/time-track/timer/switch', 'nowo_time_track_api_v1_timer_switch', '/api/time-track/v1/timer/switch', 'POST|OPTIONS'],
        ['nowo_time_track_api_timer_discard_idle', '/api/time-track/timer/discard-idle', 'nowo_time_track_api_v1_timer_discard_idle', '/api/time-track/v1/timer/discard-idle', 'POST|OPTIONS'],
        ['nowo_time_track_api_export', '/api/time-track/export', 'nowo_time_track_api_v1_export', '/api/time-track/v1/export', 'GET|OPTIONS'],
        ['nowo_time_track_api_workday', '/api/time-track/workday', 'nowo_time_track_api_v1_workday', '/api/time-track/v1/workday', 'GET|OPTIONS'],
        ['nowo_time_track_api_workday_action', '/api/time-track/workday/{action}', 'nowo_time_track_api_v1_workday_action', '/api/time-track/v1/workday/{action}', 'POST|OPTIONS'],
        ['nowo_time_track_api_balance', '/api/time-track/balance', 'nowo_time_track_api_v1_balance', '/api/time-track/v1/balance', 'GET|OPTIONS'],
        ['nowo_time_track_api_timesheets', '/api/time-track/timesheets', 'nowo_time_track_api_v1_timesheets', '/api/time-track/v1/timesheets', 'GET|OPTIONS'],
        ['nowo_time_track_api_timesheet_submit', '/api/time-track/timesheets/{periodKey}/submit', 'nowo_time_track_api_v1_timesheet_submit', '/api/time-track/v1/timesheets/{periodKey}/submit', 'POST|OPTIONS'],
        ['nowo_time_track_api_timesheet_withdraw', '/api/time-track/timesheets/{periodKey}/withdraw', 'nowo_time_track_api_v1_timesheet_withdraw', '/api/time-track/v1/timesheets/{periodKey}/withdraw', 'POST|OPTIONS'],
        ['nowo_time_track_api_calendar_connections', '/api/time-track/calendar/connections', 'nowo_time_track_api_v1_calendar_connections', '/api/time-track/v1/calendar/connections', 'GET|OPTIONS'],
        ['nowo_time_track_api_calendar_connection_revoke', '/api/time-track/calendar/connections/{id}', 'nowo_time_track_api_v1_calendar_connection_revoke', '/api/time-track/v1/calendar/connections/{id}', 'DELETE|OPTIONS'],
        ['nowo_time_track_api_suggestions', '/api/time-track/suggestions', 'nowo_time_track_api_v1_suggestions', '/api/time-track/v1/suggestions', 'GET|OPTIONS'],
        ['nowo_time_track_api_suggestions_accept', '/api/time-track/suggestions/accept', 'nowo_time_track_api_v1_suggestions_accept', '/api/time-track/v1/suggestions/accept', 'POST|OPTIONS'],
        ['nowo_time_track_api_suggestions_dismiss', '/api/time-track/suggestions/dismiss', 'nowo_time_track_api_v1_suggestions_dismiss', '/api/time-track/v1/suggestions/dismiss', 'POST|OPTIONS'],
        ['nowo_time_track_api_suggestions_resolve_keys', '/api/time-track/suggestions/resolve-keys', 'nowo_time_track_api_v1_suggestions_resolve_keys', '/api/time-track/v1/suggestions/resolve-keys', 'POST|OPTIONS'],
        ['nowo_time_track_api_entries_create', '/api/time-track/entries', 'nowo_time_track_api_v1_entries_create', '/api/time-track/v1/entries', 'POST'],
        ['nowo_time_track_api_entries_update', '/api/time-track/entries/{id}', 'nowo_time_track_api_v1_entries_update', '/api/time-track/v1/entries/{id}', 'PATCH|OPTIONS'],
        ['nowo_time_track_client_me_meetings', '/api/time-track/me/meetings', 'nowo_time_track_api_v1_me_meetings', '/api/time-track/v1/me/meetings', 'GET|OPTIONS'],
        ['nowo_time_track_api_monitoring_acknowledge', '/api/time-track/monitoring/acknowledge', 'nowo_time_track_api_v1_monitoring_acknowledge', '/api/time-track/v1/monitoring/acknowledge', 'POST|OPTIONS'],
        ['nowo_time_track_api_monitoring_screenshot_intent', '/api/time-track/monitoring/screenshots/intent', 'nowo_time_track_api_v1_monitoring_screenshot_intent', '/api/time-track/v1/monitoring/screenshots/intent', 'POST|OPTIONS'],
        ['nowo_time_track_api_monitoring_upload', '/api/time-track/monitoring/uploads/{uploadId}', 'nowo_time_track_api_v1_monitoring_upload', '/api/time-track/v1/monitoring/uploads/{uploadId}', 'PUT|OPTIONS'],
        ['nowo_time_track_api_monitoring_screenshot_discarded', '/api/time-track/monitoring/screenshots/discarded', 'nowo_time_track_api_v1_monitoring_screenshot_discarded', '/api/time-track/v1/monitoring/screenshots/discarded', 'POST|OPTIONS'],
        ['nowo_time_track_api_monitoring_screenshot_delete', '/api/time-track/monitoring/screenshots/{screenshotId}', 'nowo_time_track_api_v1_monitoring_screenshot_delete', '/api/time-track/v1/monitoring/screenshots/{screenshotId}', 'DELETE|OPTIONS'],
        ['nowo_time_track_api_monitoring_activity', '/api/time-track/monitoring/activity', 'nowo_time_track_api_v1_monitoring_activity', '/api/time-track/v1/monitoring/activity', 'POST|OPTIONS'],
        ['nowo_time_track_api_device_code', '/api/time-track/device/code', 'nowo_time_track_api_v1_device_code', '/api/time-track/v1/device/code', 'POST|OPTIONS'],
        ['nowo_time_track_api_device_token', '/api/time-track/device/token', 'nowo_time_track_api_v1_device_token', '/api/time-track/v1/device/token', 'POST|OPTIONS'],
    ];

    /** Sample values of the path placeholders (valid for the route requirements). */
    private const PLACEHOLDERS = [
        '{action}'       => 'clock-in',
        '{periodKey}'    => 'current',
        '{id}'           => 'unknown-id',
        '{uploadId}'     => '0b6b3c2e-4f1a-4c1e-9d55-1f6e2a7b9c10',
        '{screenshotId}' => 'shot-1',
    ];

    /** Response headers that must be equal on both paths. */
    private const HEADERS = ['Content-Type', 'WWW-Authenticate', 'Retry-After', 'Access-Control-Allow-Origin', 'Access-Control-Allow-Headers', 'Access-Control-Allow-Methods', 'Access-Control-Expose-Headers', 'Vary', 'Allow'];

    protected static function timeTrackConfig(): array
    {
        return ['client_api' => ['device_flow' => ['enabled' => true]]];
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function routes(): iterable
    {
        foreach (self::ROUTES as $route) {
            yield $route[0] => $route;
        }
    }

    public function testTheRouterServesEveryClientRouteByBothPaths(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $collection = $router->getRouteCollection();

        $actual = [];
        foreach ($collection->all() as $name => $route) {
            if (str_starts_with($route->getPath(), '/api/time-track/')) {
                $actual[] = $name;
            }
        }
        $expected = [];
        foreach (self::ROUTES as [$legacyName, $legacyPath, $v1Name, $v1Path, $methods]) {
            $expected[] = $legacyName;
            $expected[] = $v1Name;
            $legacy     = $collection->get($legacyName);
            $v1         = $collection->get($v1Name);
            self::assertNotNull($legacy, $legacyName);
            self::assertNotNull($v1, $v1Name);
            self::assertSame($legacyPath, $legacy->getPath());
            self::assertSame($v1Path, $v1->getPath());
            self::assertSame(explode('|', $methods), $legacy->getMethods());
            foreach (['getMethods', 'getDefaults', 'getRequirements', 'getOptions', 'getHost', 'getSchemes', 'getCondition'] as $getter) {
                self::assertSame($legacy->{$getter}(), $v1->{$getter}(), $legacyName . ' ' . $getter);
            }
        }
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual, 'every client route is in the table, by both paths');
    }

    /**
     * @param 'POST|OPTIONS'|string $methods
     */
    #[DataProvider('routes')]
    public function testBothPathsAnswerTheSame(string $legacyName, string $legacyPath, string $v1Name, string $v1Path, string $methods): void
    {
        $legacyUri = self::uri($legacyPath);
        $v1Uri     = self::uri($v1Path);

        if (in_array('OPTIONS', explode('|', $methods), true)) {
            $origin = ['HTTP_ORIGIN' => 'chrome-extension://functional'];
            self::assertSame($this->snapshot('OPTIONS', $legacyUri, null, $origin), $this->snapshot('OPTIONS', $v1Uri, null, $origin), $legacyName . ' preflight');
        }

        foreach (explode('|', $methods) as $method) {
            if ($method === 'OPTIONS') {
                continue;
            }
            $payload = $method === 'GET' ? null : [];
            self::assertSame($this->snapshot($method, $legacyUri, $payload), $this->snapshot($method, $v1Uri, $payload), $legacyName . ' ' . $method . ' without a token');

            $legacy = $this->snapshot($method, $legacyUri, $payload, [], $this->login());
            $v1     = $this->snapshot($method, $v1Uri, $payload, [], $this->login());
            self::assertSame($legacy, $v1, $legacyName . ' ' . $method . ' with a token');
            self::assertNotSame(Response::HTTP_UNAUTHORIZED, $legacy['status'], $legacyName . ': the token is accepted');
        }
    }

    public function testASessionRunsThroughTheV1PathsAndMixesWithTheLegacyOnes(): void
    {
        $login = $this->api('POST', '/api/time-track/v1/login', ['username' => 'person@example.com', 'password' => 'secret', 'clientType' => 'extension']);
        self::assertResponseIsSuccessful();
        self::assertIsArray($login);
        self::assertIsString($login['token'] ?? null);
        $token = $login['token'];

        $this->api('POST', '/api/time-track/v1/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseIsSuccessful();
        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame('task-1', $timer['timer']['taskId'] ?? null);
        $this->api('POST', '/api/time-track/timer/stop', [], $token);
        self::assertResponseIsSuccessful();
        $entries = $this->api('GET', '/api/time-track/v1/entries', null, $token);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $entries['entries'] ?? []);

        $this->api('POST', '/api/time-track/v1/logout', null, $token);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame(401);
    }

    private static function uri(string $path): string
    {
        foreach (self::PLACEHOLDERS as $placeholder => $value) {
            $path = str_replace($placeholder, $value, $path);
        }

        return $path;
    }

    /**
     * Status, the compared headers, and the body: exact for an error, its shape (keys and value types) otherwise
     * (ids, codes and timestamps differ between two calls).
     *
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $server
     *
     * @return array{status: int, headers: array<string, string|null>, body: mixed}
     */
    private function snapshot(string $method, string $uri, ?array $payload, array $server = [], ?string $token = null): array
    {
        $server += ['HTTP_ORIGIN' => 'chrome-extension://functional'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $server['CONTENT_TYPE'] = 'application/json';
        $this->client->request($method, $uri, [], [], $server, $payload === null ? '' : '{}');
        $response = $this->client->getResponse();

        $headers = [];
        foreach (self::HEADERS as $header) {
            $headers[$header] = $response->headers->get($header);
        }
        $content = (string) $response->getContent();
        $decoded = str_starts_with((string) $response->headers->get('Content-Type'), 'application/json') && $content !== '' ? json_decode($content, true) : $content;

        return [
            'status'  => $response->getStatusCode(),
            'headers' => $headers,
            'body'    => is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded : self::shape($decoded),
        ];
    }

    private static function shape(mixed $value): mixed
    {
        if (!is_array($value)) {
            return get_debug_type($value);
        }
        $shape = [];
        foreach ($value as $key => $item) {
            $shape[$key] = self::shape($item);
        }

        return $shape === [] ? [] : [array_keys($shape), $shape];
    }
}
