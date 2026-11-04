<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\IdleCheckSettings;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsProviderInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsResolver;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleFollowUp;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\ClientApiStack;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryPaginatedTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\RacingActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_slice;
use function json_decode;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * Client API 1.10 (contract v0.3): task details on tasks and timers, `startedAt` / `endedAt` clamps, idle `reassign`
 * and `features` / `limits` on `me`. The stack clock is fixed at 2026-11-02 10:00:00.
 */
final class ClientApiV110Test extends TestCase
{
    private const ORIGIN = 'https://beacon.test';

    private TestUser $user;

    private bool $providerThrows = false;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->user = new TestUser('u-1', 'u@example.com');
    }

    public function testTasksCarryProjectAndSameOriginUrlOnly(): void
    {
        $stack = $this->stack();
        $body  = $this->body($stack->apiController()->tasks($this->request('GET', $this->token($stack))));

        self::assertSame([
            'id'           => 'task-1',
            'title'        => 'Task one',
            'boardId'      => 'b-1',
            'boardTitle'   => 'Board',
            'projectId'    => 'p-1',
            'projectTitle' => 'Website',
            'url'          => self::ORIGIN . '/tasks/task-1',
        ], $body['tasks'][0]);
        self::assertNull($body['tasks'][1]['url'], 'another origin is dropped');
        self::assertNull($body['tasks'][1]['projectId']);
        self::assertNull($body['tasks'][1]['projectTitle']);
    }

    public function testTimerDetailsAreResolvedLiveAndNeverBreakTheTimer(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);

        $start = $this->body($controller->timerStart($this->request('POST', $token, ['taskId' => 'task-1'])));
        self::assertSame(['id', 'taskId', 'taskTitle', 'boardId', 'startedAt', 'lastHeartbeatAt', 'clientType', 'metadata', 'boardTitle', 'projectId', 'projectTitle', 'url'], array_keys($start['timer']));
        self::assertSame('Website', $start['timer']['projectTitle']);
        self::assertArrayNotHasKey('startedAtResult', $start);

        $timer = $this->body($controller->timer($this->request('GET', $token)));
        self::assertSame(self::ORIGIN . '/tasks/task-1', $timer['timer']['url']);
        self::assertSame('Board', $timer['timer']['boardTitle']);

        $this->providerThrows = true;
        $response             = $controller->timer($this->request('GET', $token));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['boardTitle' => null, 'projectId' => null, 'projectTitle' => null, 'url' => null], array_slice($this->body($response)['timer'], 8));
        self::assertSame('Task one', $this->body($response)['timer']['taskTitle'], 'the snapshot title stays');
    }

    public function testStartedAtIsClampedAndReported(): void
    {
        $stack      = $this->stack(entries: new InMemoryPaginatedTimeEntryRepository());
        $controller = $stack->apiController();
        $token      = $this->token($stack);

        $inRange = $this->startAt($controller, $token, '2026-11-02T08:30:00.250Z');
        self::assertSame(['requested' => $this->atom('2026-11-02T08:30:00Z'), 'applied' => $this->atom('2026-11-02T08:30:00Z'), 'clamped' => false, 'reason' => null], $inRange['startedAtResult']);
        self::assertSame($this->atom('2026-11-02T08:30:00Z'), $inRange['timer']['startedAt']);

        $this->stopAt($controller, $token, '2026-11-02T09:00:00Z');

        $overlap = $this->startAt($controller, $token, '2026-11-02T08:45:00Z');
        self::assertTrue($overlap['startedAtResult']['clamped']);
        self::assertSame('overlap', $overlap['startedAtResult']['reason']);
        self::assertSame($this->atom('2026-11-02T09:00:00Z'), $overlap['startedAtResult']['applied']);
        $this->stopAt($controller, $token, null);

        $skew = $this->startAt($controller, $token, '2026-11-02T10:01:30Z');
        self::assertSame(['clamped' => false, 'reason' => null], ['clamped' => $skew['startedAtResult']['clamped'], 'reason' => $skew['startedAtResult']['reason']]);
        self::assertSame($this->atom('2026-11-02T10:00:00Z'), $skew['startedAtResult']['applied']);
        $this->stopAt($controller, $token, null);

        $future = $this->startAt($controller, $token, '2026-11-02T10:05:00Z');
        self::assertSame('future', $future['startedAtResult']['reason']);
        self::assertSame($this->atom('2026-11-02T10:00:00Z'), $future['startedAtResult']['applied']);

        $running = $controller->timerStart($this->request('POST', $token, ['taskId' => 'task-1', 'startedAt' => '2026-11-02T09:00:00Z']));
        self::assertSame(409, $running->getStatusCode());
    }

    public function testStartedAtOlderThanTheLimitIsMaxBackdate(): void
    {
        $stack = $this->stack(settings: new ConfigClientApiSettingsProvider([], backdateMaxSeconds: 3600));
        $body  = $this->startAt($stack->apiController(), $this->token($stack), '2026-11-01T10:00:00Z');

        self::assertSame('max_backdate', $body['startedAtResult']['reason']);
        self::assertSame($this->atom('2026-11-02T09:00:00Z'), $body['startedAtResult']['applied']);
    }

    public function testBackdatingCanBeDisabled(): void
    {
        $stack      = $this->stack(settings: new ConfigClientApiSettingsProvider([], backdateMaxSeconds: 0));
        $controller = $stack->apiController();
        $token      = $this->token($stack);

        $start = $this->startAt($controller, $token, '2026-11-02T09:00:00Z');
        self::assertSame('disabled', $start['startedAtResult']['reason']);
        self::assertSame($this->atom('2026-11-02T10:00:00Z'), $start['timer']['startedAt']);

        $stack->clock->sleep(600);
        $stop = $this->stopAt($controller, $token, '2026-11-02T10:05:00Z');
        self::assertSame('disabled', $stop['endedAtResult']['reason']);
        self::assertSame(600, $stop['entry']['durationSeconds']);

        $me = $this->body($controller->me($this->request('GET', $token)));
        self::assertFalse($me['features']['start_backdate']);
        self::assertFalse($me['features']['stop_backdate']);
        self::assertSame(0, $me['limits']['backdate']['max_seconds']);
    }

    public function testMalformedStartedAtAndEndedAtAre400(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);

        foreach (['yesterday', '2026-11-02 09:00:00', 1_700_000_000, ' ', ' 2026-11-02T09:00:00Z', "2026-11-02T09:00:00Z\n", '2026-11-02T09:00:00+14:01', '2026-11-02T09:00:00-12:01', '2026-11-02T09:00:00+99:00'] as $bad) {
            $response = $controller->timerStart($this->request('POST', $token, ['taskId' => 'task-1', 'startedAt' => $bad]));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('invalid_request', $this->body($response)['error']);
        }

        $empty = $this->body($controller->timerStart($this->request('POST', $token, ['taskId' => 'task-1', 'startedAt' => ''])));
        self::assertArrayNotHasKey('startedAtResult', $empty, 'an empty value is no value');
        $this->stopAt($controller, $token, null);

        $lower = $this->startAt($controller, $token, '2026-11-02T09:30:00z');
        self::assertSame($this->atom('2026-11-02T09:30:00Z'), $lower['startedAtResult']['requested'], 'a lower-case z is RFC 3339 (EF-I4)');

        $response = $controller->timerStop($this->request('POST', $token, ['endedAt' => 'soon']));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testEndedAtIsClampedAndReported(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);

        self::assertSame(404, $controller->timerStop($this->request('POST', $token, ['endedAt' => '2026-11-02T09:00:00Z']))->getStatusCode());

        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');
        $before = $this->stopAt($controller, $token, '2026-11-02T08:00:00Z');
        self::assertSame('before_start', $before['endedAtResult']['reason']);
        self::assertSame(0, $before['entry']['durationSeconds']);

        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');
        $inRange = $this->stopAt($controller, $token, '2026-11-02T09:30:00Z');
        self::assertFalse($inRange['endedAtResult']['clamped']);
        self::assertSame(1800, $inRange['entry']['durationSeconds']);

        $this->startAt($controller, $token, '2026-11-02T09:30:00Z');
        $skew = $this->stopAt($controller, $token, '2026-11-02T10:01:00Z');
        self::assertFalse($skew['endedAtResult']['clamped']);
        self::assertSame($this->atom('2026-11-02T10:00:00Z'), $skew['endedAtResult']['applied']);

        $this->startAt($controller, $token, '2026-11-02T10:00:00Z');
        $stack->clock->sleep(60);
        $future = $this->stopAt($controller, $token, '2026-11-02T12:00:00Z');
        self::assertSame('future', $future['endedAtResult']['reason']);
        self::assertSame(60, $future['entry']['durationSeconds']);

        $this->startAt($controller, $token, '2026-11-02T10:01:00Z');
        $plain = $this->stopAt($controller, $token, null);
        self::assertArrayNotHasKey('endedAtResult', $plain);
    }

    /**
     * @return iterable<string, array{IdleFollowUp|null, string|null}>
     */
    public static function followUps(): iterable
    {
        yield 'default continue' => [null, 'task-1'];
        yield 'continue' => [IdleFollowUp::Continue, 'task-1'];
        yield 'switch' => [IdleFollowUp::Switch, 'task-2'];
        yield 'stop' => [IdleFollowUp::Stop, null];
    }

    #[DataProvider('followUps')]
    public function testReassignGivesTheSpanToAnotherTask(?IdleFollowUp $followUp, ?string $runningTask): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');
        $this->events = [];

        $payload = ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:50:00Z', 'action' => 'reassign', 'taskId' => 'task-2', 'note' => 'Standup', 'source' => 'extension'];
        if ($followUp instanceof IdleFollowUp) {
            $payload['then'] = $followUp->value;
        }

        $response = $controller->timerDiscardIdle($this->request('POST', $token, $payload));
        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);

        self::assertSame('task-1', $body['entry']['taskId']);
        self::assertSame(1800, $body['entry']['durationSeconds']);
        self::assertSame('task-2', $body['reassigned']['taskId']);
        self::assertSame(1200, $body['reassigned']['durationSeconds']);
        self::assertSame('extension', $body['reassigned']['source']);
        self::assertSame(['note' => 'Standup'], $body['reassigned']['metadata']);
        self::assertSame($runningTask, $body['timer']['taskId'] ?? null);
        if ($runningTask !== null) {
            self::assertSame($this->atom('2026-11-02T09:50:00Z'), $body['timer']['startedAt']);
            self::assertArrayHasKey('projectTitle', $body['timer']);
        }

        $events = $this->recorded();
        $stops  = array_values(array_filter($events, static fn (object $e): bool => $e instanceof TimerStopEvent));
        self::assertSame([1800, 1200], array_map(static fn (TimerStopEvent $e): int => $e->getEntry()->getDurationSeconds(), $stops));
        $idle = array_values(array_filter($events, static fn (object $e): bool => $e instanceof IdleResolvedEvent));
        self::assertCount(1, $idle);
        self::assertSame(IdleResolution::Reassign, $idle[0]->getResolution());
        self::assertSame('task-2', $idle[0]->getTargetTaskId());
        self::assertSame($followUp ?? IdleFollowUp::Continue, $idle[0]->getFollowUp());
        self::assertSame(ClientType::Extension, $idle[0]->getClientType());
        self::assertCount($runningTask === null ? 0 : 1, array_filter($events, static fn (object $e): bool => $e instanceof TimerStartEvent));
    }

    public function testReassignFromTheTimerStartWritesNoEmptyEntry(): void
    {
        $stack      = $this->stack(timers: new RacingActiveTimerRepository());
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');

        $body = $this->body($controller->timerDiscardIdle($this->request('POST', $token, ['from' => '2026-11-02T09:00:00Z', 'to' => '2026-11-02T09:10:00Z', 'action' => 'reassign', 'taskId' => 'task-2', 'then' => 'stop'])));

        self::assertNull($body['entry']);
        self::assertSame(600, $body['reassigned']['durationSeconds']);
        self::assertSame('desktop', $body['reassigned']['source'], 'the token client by default');
        self::assertNull($body['reassigned']['metadata']);
        self::assertNull($body['timer']);
    }

    public function testReassignFromWithinTheStartSecondWritesNoEmptyEntry(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');

        $body = $this->body($controller->timerDiscardIdle($this->request('POST', $token, ['from' => '2026-11-02T09:00:00.400Z', 'to' => '2026-11-02T09:10:00.900Z', 'action' => 'reassign', 'taskId' => 'task-2', 'then' => 'stop'])));

        self::assertNull($body['entry'], 'compared on whole seconds (EF-I5)');
        self::assertSame(600, $body['reassigned']['durationSeconds']);
    }

    public function testDiscardIdleRefusesPaddedTimestamps(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');

        $response = $controller->timerDiscardIdle($this->request('POST', $token, ['from' => ' 2026-11-02T09:10:00Z', 'to' => '2026-11-02T09:20:00Z', 'action' => 'keep']));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testReassignInputIsValidated(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $base       = ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:50:00Z', 'action' => 'reassign'];

        foreach ([[], ['taskId' => ' '], ['taskId' => 'task-2', 'then' => 'later'], ['taskId' => 'task-2', 'then' => 1], ['taskId' => 'task-2', 'source' => 'robot']] as $extra) {
            $response = $controller->timerDiscardIdle($this->request('POST', $token, $base + $extra));
            self::assertSame(400, $response->getStatusCode(), json_encode($extra, JSON_THROW_ON_ERROR));
            self::assertSame('invalid_request', $this->body($response)['error']);
        }

        self::assertSame(404, $controller->timerDiscardIdle($this->request('POST', $token, $base + ['taskId' => 'task-2']))->getStatusCode());

        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');
        $same = $controller->timerDiscardIdle($this->request('POST', $token, $base + ['taskId' => 'task-1']));
        self::assertSame(400, $same->getStatusCode());
        self::assertStringContainsString('keep or split', $this->body($same)['message']);

        $outside = $controller->timerDiscardIdle($this->request('POST', $token, ['from' => '2026-11-02T08:00:00Z'] + $base + ['taskId' => 'task-2']));
        self::assertSame(400, $outside->getStatusCode());

        $keep = $this->body($controller->timerDiscardIdle($this->request('POST', $token, ['action' => 'keep', 'taskId' => 'task-2'] + $base)));
        self::assertNull($keep['reassigned'], 'taskId is ignored by the other actions');
        self::assertSame('task-1', $keep['timer']['taskId']);
    }

    public function testReassignCanBeDisabled(): void
    {
        $stack      = $this->stack(settings: new ConfigClientApiSettingsProvider([], allowIdleReassign: false));
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');

        $response = $controller->timerDiscardIdle($this->request('POST', $token, ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:50:00Z', 'action' => 'reassign', 'taskId' => 'task-2']));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('action reassign is not available.', $this->body($response)['message']);
        self::assertFalse($this->body($controller->me($this->request('GET', $token)))['features']['idle_reassign']);
    }

    public function testForbiddenAndMissingTargetsAnswerTheSame403(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');
        $base = ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:50:00Z', 'action' => 'reassign'];

        $hidden  = $controller->timerDiscardIdle($this->request('POST', $token, $base + ['taskId' => 'hidden']));
        $missing = $controller->timerDiscardIdle($this->request('POST', $token, $base + ['taskId' => 'missing']));

        self::assertSame(403, $hidden->getStatusCode());
        self::assertSame($hidden->getStatusCode(), $missing->getStatusCode());
        self::assertSame($hidden->getContent(), $missing->getContent());
        self::assertSame('{"error":"task_forbidden","message":"You may not track time on this task."}', $hidden->getContent());
        self::assertSame('task-1', $this->body($controller->timer($this->request('GET', $token)))['timer']['taskId'], 'the timer is untouched');
    }

    public function testProviderRefusalAfterTheCheckerAllowedIsTheSame403(): void
    {
        $allowAll = $this->createStub(TaskAccessCheckerInterface::class);
        $allowAll->method('canTrack')->willReturn(true);
        $stack      = $this->stack();
        $controller = $stack->apiController($allowAll);
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');

        $response = $controller->timerDiscardIdle($this->request('POST', $token, ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:50:00Z', 'action' => 'reassign', 'taskId' => 'missing']));

        self::assertSame('{"error":"task_forbidden","message":"You may not track time on this task."}', $response->getContent());
        self::assertSame(403, $response->getStatusCode());
    }

    public function testReassignRacesAnswer404Or409(): void
    {
        $timers     = new RacingActiveTimerRepository();
        $stack      = $this->stack(timers: $timers);
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');
        $payload = ['from' => '2026-11-02T09:30:00Z', 'to' => '2026-11-02T09:50:00Z', 'action' => 'reassign', 'taskId' => 'task-2'];

        $timers->failNextSave($this->createStub(DriverException::class));
        self::assertSame(409, $controller->timerDiscardIdle($this->request('POST', $token, $payload))->getStatusCode());

        $this->startAt($controller, $token, '2026-11-02T09:55:00Z');
        $running = $stack->timerService->getActive($this->user);
        self::assertNotNull($running);
        $timers->staleReads(true);
        $timers->remove($running); // another request stopped it after this one read it
        $late = $controller->timerDiscardIdle($this->request('POST', $token, ['from' => '2026-11-02T09:56:00Z', 'to' => '2026-11-02T09:58:00Z'] + $payload));
        self::assertSame(404, $late->getStatusCode());
    }

    public function testMeFeaturesAndLimits(): void
    {
        $stack = $this->stack(settings: new ConfigClientApiSettingsProvider([], clockSkewSeconds: 30, idleCheckEnabled: false, idleCheckThresholdMinutes: 99, idleCheckAnswerTimeoutMinutes: 0));
        $me    = $this->body($stack->apiController()->me($this->request('GET', $this->token($stack))));

        self::assertSame(['task_details' => true, 'start_backdate' => true, 'stop_backdate' => true, 'idle_reassign' => true, 'idle_keep_note' => true, 'idle_check' => false, 'workday' => false, 'monitoring' => false], $me['features']);
        self::assertSame(['backdate' => ['max_seconds' => 86400, 'clock_skew_seconds' => 30], 'idle_check' => ['threshold_minutes' => 60, 'answer_timeout_minutes' => 1, 'user_can_disable' => false]], $me['limits']);
    }

    public function testIdleCheckProviderIsUsedClampedOrIgnored(): void
    {
        $stack  = $this->stack();
        $token  = $this->token($stack);
        $logger = new RecordingLogger();

        $values = static fn (?IdleCheckSettings $settings): IdleCheckSettingsProviderInterface => new class($settings) implements IdleCheckSettingsProviderInterface {
            public function __construct(private readonly ?IdleCheckSettings $settings)
            {
            }

            public function forUser(UserInterface $user): ?IdleCheckSettings
            {
                return $this->settings;
            }
        };
        $failing = new class implements IdleCheckSettingsProviderInterface {
            public function forUser(UserInterface $user): ?IdleCheckSettings
            {
                throw new RuntimeException('secret u@example.com');
            }
        };

        $me = fn (IdleCheckSettingsProviderInterface $provider): array => $this->body($stack->apiController(idleCheckResolver: new IdleCheckSettingsResolver($stack->settings, $provider, $logger))->me($this->request('GET', $token)));

        $null = $me($values(null));
        self::assertTrue($null['features']['idle_check']);
        self::assertSame(['threshold_minutes' => 10, 'answer_timeout_minutes' => 5, 'user_can_disable' => false], $null['limits']['idle_check']);

        $clamped = $me($values(new IdleCheckSettings(false, 1, 45)));
        self::assertFalse($clamped['features']['idle_check']);
        self::assertSame(['threshold_minutes' => 5, 'answer_timeout_minutes' => 30, 'user_can_disable' => false], $clamped['limits']['idle_check']);

        $fallback = $me($failing);
        self::assertTrue($fallback['features']['idle_check']);
        self::assertSame(['threshold_minutes' => 10, 'answer_timeout_minutes' => 5, 'user_can_disable' => false], $fallback['limits']['idle_check']);
        self::assertSame(['idle_check_provider_failed'], array_column(array_column($logger->records, 'context'), 'action'));
        self::assertNotContains('secret u@example.com', $logger->contextValues());
        self::assertNotContains('u-1', $logger->contextValues());
    }

    public function testPresenterLogsDroppedUrlsAndProviderFailuresWithoutData(): void
    {
        $logger    = new RecordingLogger();
        $stack     = $this->stack();
        $presenter = new ClientTaskPresenter($stack->timerService, null, $logger);
        $token     = $this->token($stack);
        $stack->apiController(taskPresenter: $presenter)->tasks($this->request('GET', $token));
        $stack->timerService->start($this->user, 'task-1', ClientType::Desktop);
        $this->providerThrows = true;
        $stack->apiController(taskPresenter: $presenter)->timer($this->request('GET', $token));

        self::assertSame(['task_url_dropped', 'timer_task_resolve_failed'], array_column(array_column($logger->records, 'context'), 'action'));
        foreach ($logger->contextValues() as $value) {
            self::assertStringNotContainsString('elsewhere', $value);
            self::assertStringNotContainsString('task-', $value);
            self::assertStringNotContainsString($token, $value);
        }
    }

    public function testHeartbeatAndSwitchTimersCarryTheDetails(): void
    {
        $stack      = $this->stack();
        $controller = $stack->apiController();
        $token      = $this->token($stack);
        $this->startAt($controller, $token, '2026-11-02T09:00:00Z');

        $switch = $this->body($controller->timerSwitch($this->request('POST', $token, ['taskId' => 'task-2'])));
        self::assertArrayHasKey('url', $switch['timer']);
        self::assertSame('Board 2', $switch['timer']['boardTitle']);

        $heartbeat = $this->body($controller->heartbeat($this->request('POST', $token, [])));
        self::assertArrayHasKey('projectId', $heartbeat['timer']);
    }

    private function stack(?ConfigClientApiSettingsProvider $settings = null, ?ActiveTimerRepositoryInterface $timers = null, ?TimeEntryRepositoryInterface $entries = null): ClientApiStack
    {
        $authenticator = $this->createStub(ClientAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(ClientAuthResult::success($this->user));
        $tasks = $this->createStub(TaskProviderInterface::class);
        $known = [
            'task-1' => new TaskReference('task-1', 'Task one', 'b-1', 'Board', 'p-1', ' Website ', self::ORIGIN . '/tasks/task-1'),
            'task-2' => new TaskReference('task-2', 'Task two', 'b-2', 'Board 2', '', null, 'https://elsewhere.test/tasks/task-2'),
            'hidden' => new TaskReference('hidden', 'Hidden', 'b-9', 'Private'),
        ];
        $tasks->method('canUserTrack')->willReturnCallback(static fn ($u, string $id): bool => $id === 'task-1' || $id === 'task-2');
        $tasks->method('findForUser')->willReturnCallback(function (string $id) use ($known): ?TaskReference {
            if ($this->providerThrows) {
                throw new RuntimeException('provider down for ' . $id);
            }

            return $known[$id] ?? null;
        });
        $tasks->method('listTrackableForUser')->willReturn([$known['task-1'], $known['task-2']]);

        $stack = new ClientApiStack($authenticator, $tasks, $entries, null, $settings ?? new ConfigClientApiSettingsProvider([]), null, $timers);
        foreach ([TimeTrackEvents::TIMER_START, TimeTrackEvents::TIMER_STOP, TimeTrackEvents::IDLE_RESOLVED] as $name) {
            $stack->dispatcher->addListener($name, function (object $event): void {
                $this->events[] = $event;
            });
        }

        return $stack;
    }

    /**
     * @return list<object>
     */
    private function recorded(): array
    {
        return $this->events;
    }

    private function token(ClientApiStack $stack): string
    {
        return $stack->authService->issueToken($this->user, ClientType::Desktop)['token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function startAt(TimeTrackClientApiController $controller, string $token, string $startedAt): array
    {
        $response = $controller->timerStart($this->request('POST', $token, ['taskId' => 'task-1', 'startedAt' => $startedAt]));
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        return $this->body($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function stopAt(TimeTrackClientApiController $controller, string $token, ?string $endedAt): array
    {
        $response = $controller->timerStop($this->request('POST', $token, $endedAt === null ? [] : ['endedAt' => $endedAt]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->body($response);
    }

    private function atom(string $time): string
    {
        return (new DateTimeImmutable($time))->setTimezone((new DateTimeImmutable())->getTimezone())->format(DATE_ATOM);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function request(string $method, string $token, ?array $payload = null): Request
    {
        $request = Request::create(self::ORIGIN . '/api', $method, [], [], [], ['REMOTE_ADDR' => '192.0.2.1'], $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
