<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;

use const DATE_ATOM;

/**
 * Client API 1.10 (contract v0.3) through the kernel: config reaches `GET me`, task details on tasks / timer, backdated
 * start and stop, idle reassign on the Doctrine stores, and the same 403 on reassign as on start (the hidden-vs-missing
 * comparison is in the unit test, the stub provider has no hidden task).
 */
final class ClientApiV110Test extends FunctionalTestCase
{
    protected static function timeTrackConfig(): array
    {
        return ['client_api' => [
            'backdate'   => ['max_seconds' => 7200],
            'idle_check' => ['threshold_minutes' => 20, 'answer_timeout_minutes' => 2, 'user_can_disable' => true],
            'task_url'   => ['origin' => 'https://beacon.example.com'],
        ]];
    }

    public function testMeReflectsTheConfiguration(): void
    {
        $me = $this->api('GET', '/api/time-track/me', null, $this->login());

        self::assertSame(['task_details' => true, 'start_backdate' => true, 'stop_backdate' => true, 'idle_reassign' => true, 'idle_keep_note' => true, 'idle_check' => true, 'workday' => false, 'timesheets' => false, 'balance' => false, 'calendar_suggestions' => false, 'manual_entries' => false, 'task_effort' => true, 'monitoring' => false], $me['features'] ?? null);
        self::assertSame(['backdate' => ['max_seconds' => 7200, 'clock_skew_seconds' => 120], 'idle_check' => ['threshold_minutes' => 20, 'answer_timeout_minutes' => 2, 'user_can_disable' => true]], $me['limits'] ?? null);
    }

    public function testTasksAndTimerCarryTheTaskDetails(): void
    {
        $token = $this->login();

        $tasks = $this->api('GET', '/api/time-track/tasks', null, $token);
        self::assertSame(['id' => 'task-1', 'title' => 'Implement login API', 'boardId' => 'board-1', 'boardTitle' => 'Sprint 1', 'projectId' => 'project-1', 'projectTitle' => 'Demo project', 'url' => null, 'estimateSeconds' => null, 'loggedSeconds' => null, 'loggedSecondsScope' => null, 'remainingSeconds' => null], $tasks['tasks'][0] ?? null);

        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertSame('Sprint 1', $timer['timer']['boardTitle'] ?? null);
        self::assertSame('Demo project', $timer['timer']['projectTitle'] ?? null);
        self::assertArrayHasKey('url', $timer['timer'] ?? []);
    }

    public function testBackdatedStartAndStopAndReassignOnTheDoctrineStores(): void
    {
        $token = $this->login();
        $at    = static fn (string $modifier): string => (new DateTimeImmutable($modifier))->format(DATE_ATOM);

        $start = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => $at('-3 hours')], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('max_backdate', $start['startedAtResult']['reason'] ?? null);

        $stop = $this->api('POST', '/api/time-track/timer/stop', ['endedAt' => $at('-90 minutes')], $token);
        self::assertResponseIsSuccessful();
        self::assertFalse($stop['endedAtResult']['clamped'] ?? null);
        self::assertEqualsWithDelta(1800, $stop['entry']['durationSeconds'] ?? 0, 5);

        $overlap = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => $at('-100 minutes')], $token);
        self::assertSame('overlap', $overlap['startedAtResult']['reason'] ?? null);
        self::assertSame($stop['entry']['endedAt'] ?? null, $overlap['timer']['startedAt'] ?? null);

        $reassign = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => $at('-60 minutes'), 'to' => $at('-30 minutes'), 'action' => 'reassign', 'taskId' => 'task-2'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('task-2', $reassign['reassigned']['taskId'] ?? null);
        self::assertSame('task-1', $reassign['timer']['taskId'] ?? null);

        $entries = $this->api('GET', '/api/time-track/entries', null, $token);
        self::assertSame(['task-2', 'task-1', 'task-1'], array_map(static fn (array $entry): string => $entry['taskId'], $entries['entries'] ?? []));
    }

    public function testHiddenAndMissingTasksAnswerTheSame403(): void
    {
        $token = $this->login();
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => (new DateTimeImmutable('-1 hour'))->format(DATE_ATOM)], $token);
        $payload = ['from' => (new DateTimeImmutable('-30 minutes'))->format(DATE_ATOM), 'to' => (new DateTimeImmutable('-10 minutes'))->format(DATE_ATOM), 'action' => 'reassign'];

        $responses = [];
        $this->api('POST', '/api/time-track/timer/discard-idle', $payload + ['taskId' => 'task-does-not-exist'], $token);
        $responses[] = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent(), $this->client->getResponse()->headers->get('Content-Type')];
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-does-not-exist'], $token);
        $responses[] = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent(), $this->client->getResponse()->headers->get('Content-Type')];

        self::assertSame(403, $responses[0][0]);
        self::assertSame('{"error":"task_forbidden","message":"You may not track time on this task."}', $responses[0][1]);
        self::assertSame($responses[0], $responses[1], 'reassign and start refuse a missing task alike');
    }
}
