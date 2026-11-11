<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

use function in_array;

/**
 * The Bearer client API of the browser extension and the desktop app, end to end through the route loader, the
 * Doctrine repositories and the CORS response factory (REQ-TEST-003). Paths and route names are the 1.x contract.
 */
final class ClientApiTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function endpoints(): iterable
    {
        yield 'login' => ['POST', '/api/time-track/login'];
        yield 'logout' => ['POST', '/api/time-track/logout'];
        yield 'me' => ['GET', '/api/time-track/me'];
        yield 'tasks' => ['GET', '/api/time-track/tasks'];
        yield 'timer' => ['GET', '/api/time-track/timer'];
        yield 'timer start' => ['POST', '/api/time-track/timer/start'];
        yield 'timer stop' => ['POST', '/api/time-track/timer/stop'];
        yield 'heartbeat' => ['POST', '/api/time-track/heartbeat'];
        yield 'entries' => ['GET', '/api/time-track/entries'];
    }

    #[DataProvider('endpoints')]
    public function testPreflightAnswersNoContentWithCorsForAnAllowedOrigin(string $method, string $uri): void
    {
        $this->api('OPTIONS', $uri, null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);

        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', 'chrome-extension://functional');
        self::assertResponseNotHasHeader('Access-Control-Allow-Credentials');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function protectedEndpoints(): iterable
    {
        foreach (self::endpoints() as $name => $endpoint) {
            if (!in_array($name, ['login', 'logout'], true)) {
                yield $name => $endpoint;
            }
        }
    }

    #[DataProvider('protectedEndpoints')]
    public function testProtectedEndpointsNeedAValidBearerToken(string $method, string $uri): void
    {
        $this->api($method, $uri);
        self::assertResponseStatusCodeSame(401);

        $this->api($method, $uri, null, null, ['HTTP_AUTHORIZATION' => 'Bearer    ']);
        self::assertResponseStatusCodeSame(401);

        $this->api($method, $uri, null, 'unknown-token');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginValidatesAndThrottles(): void
    {
        $this->api('POST', '/api/time-track/login', ['username' => 'person@example.com']);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/api/time-track/login', [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/time-track/login', ['username' => 'person@example.com', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);
        $this->api('POST', '/api/time-track/login', ['username' => 'person@example.com', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);

        $body = $this->api('POST', '/api/time-track/login', ['username' => 'person@example.com', 'password' => 'secret']);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => 'rate_limited', 'message' => 'Too many login attempts. Try again later.'], $body);
    }

    public function testTimerLifecycle(): void
    {
        $token = $this->login();

        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame([
            'userId'       => (string) $this->user->getId(),
            'identifier'   => 'person@example.com',
            'displayName'  => null,
            'scopes'       => ['timer:read', 'timer:write', 'tasks:read', 'entries:read'],
            'workingHours' => null,
            'locale'       => null,
            'daysOff'      => null,
            'features'     => ['task_details' => true, 'start_backdate' => true, 'stop_backdate' => true, 'idle_reassign' => true, 'idle_keep_note' => true, 'idle_check' => true, 'workday' => false, 'timesheets' => false, 'balance' => false, 'calendar_suggestions' => false, 'manual_entries' => false, 'task_effort' => true, 'meetings' => false, 'monitoring' => false],
            'limits'       => ['backdate' => ['max_seconds' => 86400, 'clock_skew_seconds' => 120], 'idle_check' => ['threshold_minutes' => 10, 'answer_timeout_minutes' => 5, 'user_can_disable' => false]],
            'timesheet'    => null,
            // Since 1.14 (contract §22.8).
            'meetings' => null,
            'links'    => ['calendars' => null, 'workingTime' => null],
        ], $me);

        $tasks = $this->api('GET', '/api/time-track/tasks?search=unit&limit=5', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['task-2'], array_column($tasks['tasks'] ?? [], 'id'));

        $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseStatusCodeSame(204);

        $this->api('POST', '/api/time-track/heartbeat', ['isIdle' => true], $token);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/api/time-track/timer/stop', null, $token);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/api/time-track/timer/start', null, $token);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/time-track/timer/start', ['clientType' => 'desktop'], $token);
        self::assertResponseStatusCodeSame(400);

        $missing = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'missing-task'], $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('task_forbidden', $missing['error'] ?? null);

        $metadata = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'metadata' => ['url' => 'https://example.com']], $token);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('metadata_not_allowed', $metadata['error'] ?? null);

        $started = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'clientType' => 'desktop'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('task-1', $started['timer']['taskId'] ?? null);
        self::assertSame('desktop', $started['timer']['clientType'] ?? null);

        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-2', 'clientType' => 'not-a-client'], $token);
        self::assertResponseStatusCodeSame(409);

        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame('task-1', $timer['timer']['taskId'] ?? null);

        $beat = $this->api('POST', '/api/time-track/heartbeat', ['isIdle' => true], $token);
        self::assertResponseIsSuccessful();
        self::assertNull($beat['timer']['metadata']['isIdle'] ?? null, 'isIdle is ignored since 1.9');
        self::assertTrue($beat['deprecated'] ?? null);
        self::assertResponseHeaderSame('Deprecation', 'true');
        self::assertSame(300, $beat['idleThresholdSeconds'] ?? null);

        $stopped = $this->api('POST', '/api/time-track/timer/stop', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame('task-1', $stopped['entry']['taskId'] ?? null);
        self::assertSame('desktop', $stopped['entry']['source'] ?? null);

        $entries = $this->api('GET', '/api/time-track/entries', null, $token);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $entries['entries'] ?? []);

        $own = $this->api('GET', '/api/time-track/entries?userId=' . $this->user->getId(), null, $token);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $own['entries'] ?? []);

        $other = $this->createUser('other@example.com');
        $this->api('GET', '/api/time-track/entries?userId=' . $other->getId(), null, $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLogoutRevokesTheToken(): void
    {
        $token = $this->login(clientType: 'extension');

        $this->api('POST', '/api/time-track/logout');
        self::assertResponseStatusCodeSame(204);

        $this->api('POST', '/api/time-track/logout', null, $token);
        self::assertResponseStatusCodeSame(204);

        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertResponseStatusCodeSame(401);
    }
}
