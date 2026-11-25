<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Event\ClientApiEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\InternalTestSubjectProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_values;
use function count;

use const DATE_ATOM;

/**
 * Since 2.1.0 (audit ZBQ of the 2.1.0 client API): a stop that clears the subject of time without a task (`allow_without_task`
 * off) is `409 allocation_required` with the timer (ZBQ-L1); a change of the subject of a running task timer asks the
 * task access again (`403 task_forbidden`, ZBQ-L3); `POST entries` labels the subject after the commit, never under the
 * owner's lock, and the replay answers the labelled body (ZBQ-L5); `GET activity-types` is rate-limited per token.
 */
final class AllocationAuditClientApiTest extends FunctionalTestCase
{
    /** @var list<ClientApiEvent> */
    private array $audit = [];

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'manual_entries' => ['enabled' => true],
            'client_api'     => ['rate_limit' => ['per_token' => 0], 'task_access_checker' => RecordingTaskAccessChecker::class, 'task_url' => ['origin' => 'https://crm.example.test']],
            'entries'        => ['allow_without_task' => false, 'subjects' => ['search' => ['max_per_minute' => 2]]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->customers()->reset();
        $this->internals()->reset();
        $this->activities()->reset();
        $this->customers()->loggable = ['c-1', 'c-2'];
        $this->customers()->readable = ['c-1', 'c-2'];
        $this->access()->denied      = ['task-2'];
        $this->access()->checked     = [];
        $scopes                      = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a', 'org-b']];
        $this->audit         = [];
        $dispatcher          = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::CLIENT_API, function (ClientApiEvent $event): void {
            $this->audit[] = $event;
        });
    }

    protected function tearDown(): void
    {
        $this->customers()->reset();
        $this->internals()->reset();
        $this->activities()->reset();
        parent::tearDown();
    }

    /**
     * Audit ZBQ-L1: `POST timer/stop {"subject": null}` on a subject-alone timer without `allow_without_task` would leave
     * the time unallocated: 409 `allocation_required` with the running timer, nothing written.
     */
    public function testAStopClearingTheSubjectOfTimeWithoutATaskIsRefusedWithTheTimer(): void
    {
        $token = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead, ClientScope::EntriesRead);
        $start = $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertResponseStatusCodeSame(201);

        $refused = $this->api('POST', '/api/time-track/timer/stop', ['subject' => null], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['allocation_required', $start['timer']['id'] ?? null, 'c-1', 'customer c-1'], [$refused['error'] ?? null, $refused['timer']['id'] ?? null, $refused['timer']['subject']['id'] ?? null, $refused['timer']['subject']['label'] ?? null]);
        self::assertSame('c-1', $this->api('GET', '/api/time-track/timer', null, $token)['timer']['subject']['id'] ?? null, 'the timer keeps running');
        self::assertSame([], $this->api('GET', '/api/time-track/entries', null, $token)['entries'] ?? null, 'nothing is written');

        // The same stop keeping its subject goes through.
        $stop = $this->api('POST', '/api/time-track/timer/stop', [], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('c-1', $stop['entry']['subject']['id'] ?? null);
    }

    /**
     * Audit ZBQ-L3: a running task timer whose task the access checker now refuses cannot change its subject through
     * `timer/allocate` or `timer/stop`: 403 `task_forbidden` (audited), the timer unchanged. An activity type alone is
     * no subject change, and a task-less timer has no task to check.
     */
    public function testChangingTheSubjectOfATaskTimerAsksTheTaskAccessAgain(): void
    {
        $token = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertResponseStatusCodeSame(201);
        $this->access()->denied  = ['task-1'];
        $this->access()->checked = [];
        $canLog                  = count($this->customers()->canLogCalls);

        foreach ([
            ['/api/time-track/timer/allocate', ['subject' => ['type' => 'customer', 'id' => 'c-2']]],
            ['/api/time-track/timer/allocate', ['subject' => null]],
            ['/api/time-track/timer/stop', ['subject' => ['type' => 'customer', 'id' => 'c-2']]],
            ['/api/time-track/timer/stop', ['subject' => null, 'activityTypeKey' => 'dev']],
        ] as [$uri, $body]) {
            $answer = $this->api('POST', $uri, $body, $token);
            self::assertSame([403, '{"error":"task_forbidden","message":"You may not track time on this task."}'], [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()], $uri);
            self::assertSame('task_forbidden', $answer['error'] ?? null);
        }
        self::assertSame(['task-1', 'task-1', 'task-1', 'task-1'], $this->access()->checked);
        self::assertCount($canLog, $this->customers()->canLogCalls, 'the task first: the subject is never asked');
        self::assertNotSame([], $this->denials('task_forbidden'), 'audited as a denial (throttled per interval)');
        $timer = $this->api('GET', '/api/time-track/timer', null, $token)['timer'] ?? [];
        self::assertSame(['task-1', 'c-1', null], [$timer['taskId'] ?? null, $timer['subject']['id'] ?? null, $timer['activityType'] ?? null]);

        // No subject change: no task check.
        $allocated = $this->api('POST', '/api/time-track/timer/allocate', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'dev'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('dev', $allocated['timer']['activityType']['key'] ?? null);
        $stop = $this->api('POST', '/api/time-track/timer/stop', ['activityTypeKey' => 'support'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['task-1', 'c-1', 'support'], [$stop['entry']['taskId'] ?? null, $stop['entry']['subject']['id'] ?? null, $stop['entry']['activityType']['key'] ?? null]);
        self::assertCount(4, $this->access()->checked);

        // A task-less timer: nothing to check.
        $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertSame('c-2', $this->api('POST', '/api/time-track/timer/allocate', ['subject' => ['type' => 'customer', 'id' => 'c-2']], $token)['timer']['subject']['id'] ?? null);
        self::assertSame('c-1', $this->api('POST', '/api/time-track/timer/stop', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $token)['entry']['subject']['id'] ?? null);
        self::assertCount(4, $this->access()->checked);
    }

    /**
     * Audit ZBQ-L3: the access checker allows the task but the task provider no longer knows it (the timer service asks
     * it again): still 403 `task_forbidden` on both routes, never a 500.
     */
    public function testATaskTheProviderNoLongerKnowsIsForbiddenToo(): void
    {
        $token = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertResponseStatusCodeSame(201);
        // The task disappears from the provider while the timer runs (the stub knows task-1..3 only).
        $this->bundleEntityManager()->getConnection()->executeStatement('UPDATE time_track_active_timers SET task_id = ?', ['task-gone']);
        $this->clearEntityManagers();
        $this->access()->checked = [];

        foreach (['/api/time-track/timer/allocate', '/api/time-track/timer/stop'] as $uri) {
            $answer = $this->api('POST', $uri, ['subject' => ['type' => 'customer', 'id' => 'c-2']], $token);
            self::assertSame([403, 'task_forbidden'], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null], $uri);
        }
        self::assertSame(['task-gone', 'task-gone'], $this->access()->checked, 'the checker allowed it');
        self::assertSame('c-1', $this->api('GET', '/api/time-track/timer', null, $token)['timer']['subject']['id'] ?? null);
    }

    /**
     * Audit ZBQ: `GET activity-types` asks the host port once per request, rate-limited per token (bucket of the subject
     * search, `entries.subjects.search.max_per_minute`): 429 with Retry-After; another token is not limited.
     */
    public function testActivityTypesAreRateLimitedPerToken(): void
    {
        $token = $this->token(ClientScope::TasksRead);
        for ($i = 0; $i < 2; ++$i) {
            $this->api('GET', '/api/time-track/activity-types', null, $token);
            self::assertResponseIsSuccessful();
        }
        $limited = $this->api('GET', '/api/time-track/activity-types', null, $token);
        self::assertSame([429, 'rate_limited'], [$this->client->getResponse()->getStatusCode(), $limited['error'] ?? null]);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame(['org-a', 'org-a'], $this->activities()->asked, 'the refused request never reached the provider');

        $this->api('GET', '/api/time-track/activity-types', null, $this->token(ClientScope::TasksRead));
        self::assertResponseIsSuccessful();
        // The subject search keeps its own bucket.
        $this->api('GET', '/api/time-track/subjects/search?type=customer', null, $token);
        self::assertResponseIsSuccessful();
    }

    /**
     * Audit ZBQ-L5: `POST entries` asks the subject provider for the label only after the commit (no transaction open
     * on the connection while describe() runs); the 201 is labelled, and the replay with the same Idempotency-Key
     * answers the same labelled body.
     */
    public function testPostEntriesLabelsTheSubjectAfterTheCommitAndReplaysTheLabelledBody(): void
    {
        $token                         = $this->token(ClientScope::EntriesWrite);
        $connection                    = $this->bundleEntityManager()->getConnection();
        $inside                        = [];
        $this->customers()->onDescribe = static function () use ($connection, &$inside): void {
            $inside[] = $connection->isTransactionActive();
        };
        $start = (new DateTimeImmutable('today -2 days'))->setTime(9, 0);
        $body  = ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'dev', 'startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];

        $created = $this->api('POST', '/api/time-track/entries', $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'zbq-l5']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['type' => 'customer', 'id' => 'c-1', 'label' => 'customer c-1', 'url' => 'https://crm.example.test/customer/c-1'], $created['entry']['subject'] ?? null);
        self::assertSame(['key' => 'dev', 'label' => 'Development'], $created['entry']['activityType'] ?? null);
        self::assertNotSame([], $inside, 'the provider labelled the answer');
        self::assertSame([], array_values(array_filter($inside)), 'never under the owner\'s lock');

        $calls  = count($this->customers()->describeCalls);
        $replay = $this->api('POST', '/api/time-track/entries', $body, $token, ['HTTP_IDEMPOTENCY_KEY' => 'zbq-l5']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($created, $replay, 'the stored body is the labelled one');
        self::assertCount($calls, $this->customers()->describeCalls, 'a replay asks nothing');
    }

    /**
     * Denials audited with the reason.
     *
     * @return list<ClientApiEvent>
     */
    private function denials(string $reason): array
    {
        return array_values(array_filter($this->audit, static fn (ClientApiEvent $event): bool => $event->action === ClientApiAuditAction::RequestDenied && $event->reason === $reason));
    }

    private function access(): RecordingTaskAccessChecker
    {
        $checker = self::getContainer()->get(RecordingTaskAccessChecker::class);
        self::assertInstanceOf(RecordingTaskAccessChecker::class, $checker);

        return $checker;
    }

    private function customers(): CustomerTestSubjectProvider
    {
        $provider = self::getContainer()->get(CustomerTestSubjectProvider::class);
        self::assertInstanceOf(CustomerTestSubjectProvider::class, $provider);

        return $provider;
    }

    private function internals(): InternalTestSubjectProvider
    {
        $provider = self::getContainer()->get(InternalTestSubjectProvider::class);
        self::assertInstanceOf(InternalTestSubjectProvider::class, $provider);

        return $provider;
    }

    private function activities(): FixedActivityTypeProvider
    {
        $provider = self::getContainer()->get(FixedActivityTypeProvider::class);
        self::assertInstanceOf(FixedActivityTypeProvider::class, $provider);

        return $provider;
    }

    /**
     * A token of the signed-up user with the scopes.
     */
    private function token(ClientScope ...$scopes): string
    {
        $user = $this->entityManager()->find(AppUser::class, $this->user->getId());
        self::assertInstanceOf(UserInterface::class, $user);
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($user, ClientType::Desktop, null, array_values($scopes))['token'];
    }
}
