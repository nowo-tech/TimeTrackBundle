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
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\InternalTestSubjectProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function array_fill;
use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function str_repeat;
use function trim;
use function usort;

use const DATE_ATOM;

/**
 * Since 2.1.0 (Beacon spec 256, client API contract v0.4): subjects and activity types on the client API — `GET me`
 * features, `timer/start` / `switch` / `stop` / `allocate` / `discard-idle`, `POST entries`, `subjects/search`,
 * `activity-types`, the masked subject on every read surface, the audit and the no-oracle refusals.
 */
final class AllocationClientApiTest extends FunctionalTestCase
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
            'client_api'     => ['rate_limit' => ['per_token' => 0], 'task_url' => ['origin' => 'https://crm.example.test']],
            'entries'        => ['allow_without_task' => true, 'subjects' => ['search' => ['max_per_minute' => 3]]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->customers()->reset();
        $this->internals()->reset();
        $this->activities()->reset();
        $scopes = self::getContainer()->get(FixedScopeProvider::class);
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

    public function testMeListsTheSubjectTypesAndActivityTypes(): void
    {
        $me       = $this->api('GET', '/api/time-track/me', null, $this->token(ClientScope::TimerRead));
        $subjects = $me['features']['subjects'] ?? [];
        usort($subjects, static fn (array $a, array $b): int => $a['type'] <=> $b['type']);

        self::assertSame([['type' => 'customer', 'label' => 'Customer', 'allowsTask' => true], ['type' => 'internal', 'label' => 'Internal', 'allowsTask' => false]], $subjects);
        self::assertSame([true, false, false], [$me['features']['activityTypes'] ?? null, $me['features']['requireAllocation'] ?? null, $me['features']['requireActivityType'] ?? null]);
    }

    public function testTimerStartOnASubjectATaskAndSubjectOrNothing(): void
    {
        $token                       = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead);
        $this->customers()->loggable = ['c-1'];
        $this->customers()->readable = ['c-1'];

        $start = $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'dev', 'scopeKey' => 'org-b'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['type' => 'customer', 'id' => 'c-1', 'label' => 'customer c-1', 'url' => 'https://crm.example.test/customer/c-1'], $start['timer']['subject'] ?? null);
        self::assertSame(['key' => 'dev', 'label' => 'Development'], $start['timer']['activityType'] ?? null);
        self::assertSame([['actor' => (string) $this->user->getId(), 'scope' => 'org-b', 'id' => 'c-1']], $this->customers()->canLogCalls, 'canLog for the token user in the requested scope');
        self::assertSame([[ClientApiAuditAction::SubjectAllocated, 'customer']], $this->audited(ClientApiAuditAction::SubjectAllocated), 'the type, never the id');

        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertSame('c-1', $timer['timer']['subject']['id'] ?? null);
        $stop = $this->api('POST', '/api/time-track/timer/stop', [], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['customer', 'c-1', 'dev', true, 'org-b'], [$stop['entry']['subject']['type'] ?? null, $stop['entry']['subject']['id'] ?? null, $stop['entry']['activityType']['key'] ?? null, $stop['entry']['billable'] ?? null, $stop['entry']['scopeKey'] ?? null], 'the activity type default makes it billable');

        // Task and subject: the subject is checked in the task's scope.
        $this->customers()->canLogCalls = [];
        $both                           = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-3', 'subject' => ['type' => 'customer', 'id' => 'c-1'], 'scopeKey' => 'org-a'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['task-3', 'c-1', null], [$both['timer']['taskId'] ?? null, $both['timer']['subject']['id'] ?? null, $this->field($both, 'timer', 'activityType')]);
        self::assertSame('org-b', $this->customers()->canLogCalls[0]['scope'] ?? null, 'task-3 lives in org-b; the requested scope is ignored');
        $this->api('POST', '/api/time-track/timer/stop', [], $token);

        // Neither: allowed with allow_without_task on and require_allocation off.
        $none = $this->api('POST', '/api/time-track/timer/start', [], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([null, null], [$this->field($none, 'timer', 'subject'), $this->field($none, 'timer', 'activityType')]);
        $this->api('POST', '/api/time-track/timer/stop', [], $token);
        self::assertResponseIsSuccessful();
    }

    public function testRefusedSubjectsShareOneAnswerAndNothingStarts(): void
    {
        $token                         = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead);
        $this->customers()->loggable   = ['c-1'];
        $this->customers()->loggableBy = ['someone-else' => ['c-2']];
        $this->internals()->loggable   = ['i-1'];

        $answers = [];
        foreach ([['type' => 'vendor', 'id' => 'c-1'], ['type' => 'customer', 'id' => 'c-404'], ['type' => 'customer', 'id' => 'c-2']] as $subject) {
            $this->api('POST', '/api/time-track/timer/start', ['subject' => $subject], $token);
            $answers[] = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];
        }
        self::assertSame(array_fill(0, 3, [403, '{"error":"subject_forbidden","message":"You may not log time on this subject."}']), $answers, 'unknown type, unknown id and no access: one answer');

        // A provider that throws is a denial, never a 500 with its details.
        $this->customers()->fail = true;
        $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('secret', (string) $this->client->getResponse()->getContent());
        $this->customers()->fail = false;

        // A type that disallows tasks next to a task.
        $conflict = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'subject' => ['type' => 'internal', 'id' => 'i-1']], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('allocation_conflict', $conflict['error'] ?? null);

        // Task access is checked first: the subject is never asked.
        $this->customers()->canLogCalls = [];
        $task                           = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-404', 'subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('task_forbidden', $task['error'] ?? null);
        self::assertSame([], $this->customers()->canLogCalls);

        // A scope that is not the user's.
        $scope = $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'scopeKey' => 'org-z'], $token);
        self::assertSame([403, 'subject_forbidden'], [$this->client->getResponse()->getStatusCode(), $scope['error'] ?? null]);

        foreach (['nope' => 'activity_type_unknown', 'legacy' => 'activity_type_inactive'] as $key => $code) {
            $answer = $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => $key], $token);
            self::assertSame([422, $code], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null]);
        }

        foreach ([
            ['subject' => ['type' => 'Customer', 'id' => 'c-1']],
            ['subject' => ['type' => 'customer']],
            ['subject' => ['type' => 'customer', 'id' => 'c-1', 'label' => 'x']],
            ['subject' => ['type' => 'customer', 'id' => 7]],
            ['subject' => 'customer:c-1'],
            ['subject' => ['type' => 'customer', 'id' => "c-1\u{202E}"]],
            ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'Bad Key'],
            ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'scopeKey' => ''],
            ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'scopeKey' => 5],
        ] as $bad) {
            $answer = $this->api('POST', '/api/time-track/timer/start', $bad, $token);
            self::assertSame([400, 'invalid_request'], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null]);
        }

        $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->audited(ClientApiAuditAction::SubjectAllocated));
        self::assertNotSame([], $this->audited(ClientApiAuditAction::RequestDenied), 'subject_forbidden is audited as a denial');
    }

    public function testSwitchAndStopCarryTheAllocation(): void
    {
        $token                       = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead);
        $this->customers()->loggable = ['c-1'];
        $this->internals()->loggable = ['i-1'];
        $this->internals()->readable = ['i-1'];

        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        $refused = $this->api('POST', '/api/time-track/timer/switch', ['subject' => ['type' => 'internal', 'id' => 'i-404']], $token);
        self::assertSame([403, 'subject_forbidden'], [$this->client->getResponse()->getStatusCode(), $refused['error'] ?? null]);
        $refused = $this->api('POST', '/api/time-track/timer/switch', ['subject' => ['type' => 'internal']], $token);
        self::assertResponseStatusCodeSame(400);
        $refused = $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-404', 'subject' => ['type' => 'internal', 'id' => 'i-1']], $token);
        self::assertSame('task_forbidden', $refused['error'] ?? null);
        self::assertSame('task-1', $this->api('GET', '/api/time-track/timer', null, $token)['timer']['taskId'] ?? null, 'a refused switch leaves the timer running');

        $switch = $this->api('POST', '/api/time-track/timer/switch', ['subject' => ['type' => 'internal', 'id' => 'i-1'], 'activityTypeKey' => 'support', 'scopeKey' => 'org-b'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['task-1', null], [$switch['entry']['taskId'] ?? null, $this->field($switch, 'entry', 'subject')]);
        self::assertSame(['internal', 'i-1', 'internal i-1', 'https://crm.example.test/internal/i-1', 'support'], [$switch['timer']['subject']['type'] ?? null, $switch['timer']['subject']['id'] ?? null, $switch['timer']['subject']['label'] ?? null, $this->field($switch, 'timer', 'subject', 'url'), $switch['timer']['activityType']['key'] ?? null], 'a url on the allowed origin is kept');

        // Stop: change the subject (forbidden refused and nothing written), then a valid change.
        $refused = $this->api('POST', '/api/time-track/timer/stop', ['subject' => ['type' => 'customer', 'id' => 'c-404']], $token);
        self::assertSame([403, 'subject_forbidden'], [$this->client->getResponse()->getStatusCode(), $refused['error'] ?? null]);
        $this->api('POST', '/api/time-track/timer/stop', ['subject' => 'x'], $token);
        self::assertResponseStatusCodeSame(400);
        $inactive = $this->api('POST', '/api/time-track/timer/stop', ['activityTypeKey' => 'legacy'], $token);
        self::assertSame([422, 'activity_type_inactive', null], [$this->client->getResponse()->getStatusCode(), $inactive['error'] ?? null, $inactive['timer'] ?? null]);
        self::assertSame('i-1', $this->api('GET', '/api/time-track/timer', null, $token)['timer']['subject']['id'] ?? null);

        $this->audit = [];
        $stop        = $this->api('POST', '/api/time-track/timer/stop', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => null], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['customer', true, null], [$stop['entry']['subject']['type'] ?? null, $stop['entry']['subject']['hidden'] ?? null, $this->field($stop, 'entry', 'activityType')], 'c-1 is loggable but not readable: masked');
        self::assertSame([[ClientApiAuditAction::SubjectAllocated, 'customer']], $this->audited(ClientApiAuditAction::SubjectAllocated));
        $this->api('POST', '/api/time-track/timer/stop', [], $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAllocateChangesTheRunningTimer(): void
    {
        $token                       = $this->token(ClientScope::TimerWrite);
        $this->customers()->loggable = ['c-1'];
        $this->customers()->readable = ['c-1'];
        $this->internals()->loggable = ['i-1'];

        $this->api('OPTIONS', '/api/time-track/timer/allocate', null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        $this->api('POST', '/api/time-track/timer/allocate', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $this->token(ClientScope::TimerRead));
        self::assertResponseStatusCodeSame(403);
        $none = $this->api('POST', '/api/time-track/timer/allocate', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        self::assertSame([404, 'no_active_timer'], [$this->client->getResponse()->getStatusCode(), $none['error'] ?? null]);
        foreach ([[], ['subject' => ['type' => 'customer']], ['activityTypeKey' => 'BAD']] as $bad) {
            $answer = $this->api('POST', '/api/time-track/timer/allocate', $bad, $token);
            self::assertSame([400, 'invalid_request'], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null]);
        }

        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        $allocated = $this->api('POST', '/api/time-track/timer/allocate', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'meeting'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['task-1', 'c-1', 'customer c-1', 'meeting'], [$allocated['timer']['taskId'] ?? null, $allocated['timer']['subject']['id'] ?? null, $allocated['timer']['subject']['label'] ?? null, $allocated['timer']['activityType']['key'] ?? null]);
        self::assertSame([[ClientApiAuditAction::SubjectAllocated, 'customer']], $this->audited(ClientApiAuditAction::SubjectAllocated));

        foreach ([
            [['subject' => ['type' => 'internal', 'id' => 'i-1']], 422, 'allocation_conflict'],
            [['subject' => ['type' => 'customer', 'id' => 'c-9']], 403, 'subject_forbidden'],
            [['activityTypeKey' => 'nope'], 422, 'activity_type_unknown'],
            [['activityTypeKey' => 'legacy'], 422, 'activity_type_inactive'],
        ] as [$body, $status, $code]) {
            $answer = $this->api('POST', '/api/time-track/timer/allocate', $body, $token);
            self::assertSame([$status, $code], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null]);
        }

        // Clearing the subject of a task timer is always allowed; the activity type too.
        $cleared = $this->api('POST', '/api/time-track/timer/allocate', ['subject' => null, 'activityTypeKey' => null], $token);
        self::assertResponseIsSuccessful();
        self::assertSame([null, null], [$this->field($cleared, 'timer', 'subject'), $this->field($cleared, 'timer', 'activityType')]);
        self::assertCount(1, $this->audited(ClientApiAuditAction::SubjectAllocated), 'a clearing is not a subject allocation');

        // A subject-alone timer may be cleared while time without a task is allowed (require_allocation off).
        $this->api('POST', '/api/time-track/timer/switch', ['subject' => ['type' => 'customer', 'id' => 'c-1']], $token);
        $cleared = $this->api('POST', '/api/time-track/timer/allocate', ['subject' => null], $token);
        self::assertResponseIsSuccessful();
        self::assertSame([null, ''], [$this->field($cleared, 'timer', 'subject'), $this->field($cleared, 'timer', 'taskId')]);
    }

    public function testSubjectSearchIsBoundedAndRateLimited(): void
    {
        $token = $this->token(ClientScope::TasksRead);
        $c1    = new TimeSubjectRef('customer', 'c-1');

        $this->api('OPTIONS', '/api/time-track/subjects/search', null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', '/api/time-track/subjects/search?type=customer', null, $this->token(ClientScope::TimerWrite));
        self::assertResponseStatusCodeSame(403);
        foreach (['type=Customer', 'type=customer&q=' . str_repeat('a', 101), 'type=customer&limit=0', 'type=customer&limit=51', 'type=customer&limit=abc', 'type=customer&limit=-1', 'type=customer&scopeKey=org-z', 'type=customer&scopeKey='] as $query) {
            $answer = $this->api('GET', '/api/time-track/subjects/search?' . $query, null, $token);
            self::assertSame([400, 'invalid_request'], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null], $query);
        }

        $this->customers()->searchResults = [
            new TimeSubjectLabel($c1, 'ACME <script>', 'https://crm.example.test/c/1', 'Madrid'),
            new TimeSubjectLabel(new TimeSubjectRef('internal', 'i-1'), 'Other type'),
            new TimeSubjectLabel($c1, 'Duplicate'),
            new TimeSubjectLabel(new TimeSubjectRef('customer', 'c-2'), 'Evil', 'https://evil.example/x'),
            new TimeSubjectLabel(new TimeSubjectRef('customer', 'c-3'), 'Cut off'),
        ];
        $found = $this->api('GET', '/api/time-track/subjects/search?type=customer&q=ac&limit=2&scopeKey=org-b', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['type' => 'customer', 'id' => 'c-1', 'label' => 'ACME <script>', 'hint' => 'Madrid', 'url' => 'https://crm.example.test/c/1'],
            ['type' => 'customer', 'id' => 'c-2', 'label' => 'Evil', 'hint' => null, 'url' => null],
        ], $found['subjects'] ?? null);
        self::assertSame([['actor' => (string) $this->user->getId(), 'scope' => 'org-b', 'query' => 'ac', 'limit' => 2]], $this->customers()->searchCalls);

        // A failing provider answers nothing; then the per-token limit (3 per minute) answers 429 with Retry-After.
        $this->customers()->fail = true;
        self::assertSame([], $this->api('GET', '/api/time-track/subjects/search?type=customer', null, $token)['subjects'] ?? null);
        self::assertSame([], $this->api('GET', '/api/time-track/subjects/search?type=vendor', null, $token)['subjects'] ?? null, 'an unknown type answers nothing');
        $limited = $this->api('GET', '/api/time-track/subjects/search?type=customer', null, $token);
        self::assertSame([429, 'rate_limited'], [$this->client->getResponse()->getStatusCode(), $limited['error'] ?? null]);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->api('GET', '/api/time-track/subjects/search?type=customer', null, $this->token(ClientScope::TasksRead));
        self::assertResponseIsSuccessful();
    }

    public function testActivityTypesOfOneOfTheUsersScopes(): void
    {
        $token = $this->token(ClientScope::TasksRead);

        $this->api('OPTIONS', '/api/time-track/activity-types', null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', '/api/time-track/activity-types', null, $this->token(ClientScope::TimerWrite));
        self::assertResponseStatusCodeSame(403);
        $types = $this->api('GET', '/api/time-track/activity-types', null, $token);
        self::assertSame(['scopeKey' => 'org-a', 'activityTypes' => [['key' => 'dev', 'label' => 'Development', 'group' => 'Build'], ['key' => 'support', 'label' => 'Support', 'group' => null], ['key' => 'meeting', 'label' => 'Meeting', 'group' => null]]], $types);
        self::assertSame('org-b', $this->api('GET', '/api/time-track/activity-types?scopeKey=org-b', null, $token)['scopeKey'] ?? null);
        self::assertSame(['org-a', 'org-b'], $this->activities()->asked);
        $this->api('GET', '/api/time-track/activity-types?scopeKey=org-c', null, $token);
        self::assertResponseStatusCodeSame(400);
    }

    public function testIdleReassignToASubject(): void
    {
        $token                       = $this->token(ClientScope::TimerWrite);
        $this->customers()->loggable = ['c-1'];
        $at                          = static fn (string $modifier): string => (new DateTimeImmutable($modifier))->format(DATE_ATOM);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => $at('-60 minutes')], $token);
        $span = ['from' => $at('-50 minutes'), 'to' => $at('-40 minutes'), 'action' => 'reassign'];

        foreach ([[['subject' => ['type' => 'customer']], 400], [[], 400], [['subject' => ['type' => 'customer', 'id' => 'c-404']], 403], [['taskId' => 'task-404', 'subject' => ['type' => 'customer', 'id' => 'c-1']], 403]] as [$extra, $status]) {
            $this->api('POST', '/api/time-track/timer/discard-idle', $span + $extra, $token);
            self::assertResponseStatusCodeSame($status);
        }

        $reassign = $this->api('POST', '/api/time-track/timer/discard-idle', $span + ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'support'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['', 'customer', true, 'support', 'task-1'], [$reassign['reassigned']['taskId'] ?? null, $reassign['reassigned']['subject']['type'] ?? null, $reassign['reassigned']['subject']['hidden'] ?? null, $reassign['reassigned']['activityType']['key'] ?? null, $reassign['timer']['taskId'] ?? null], 'not readable by the viewer: masked');
        self::assertSame('task-1', $reassign['entry']['taskId'] ?? null);
        self::assertSame([[ClientApiAuditAction::SubjectAllocated, 'customer']], $this->audited(ClientApiAuditAction::SubjectAllocated));
    }

    public function testPostEntriesWithASubjectAndTheIdempotencyHash(): void
    {
        $token                       = $this->token(ClientScope::EntriesWrite);
        $this->customers()->loggable = ['c-1', 'c-2'];
        $this->customers()->readable = ['c-1'];

        $alone = $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'support', 'scopeKey' => 'org-b'] + $this->body(3), $token, ['HTTP_IDEMPOTENCY_KEY' => 'e-1']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['', 'org-b', 'c-1', 'customer c-1', 'support', false], [$alone['entry']['taskId'] ?? null, $alone['entry']['scopeKey'] ?? null, $alone['entry']['subject']['id'] ?? null, $alone['entry']['subject']['label'] ?? null, $alone['entry']['activityType']['key'] ?? null, $alone['entry']['billable'] ?? null]);
        self::assertSame([[ClientApiAuditAction::SubjectAllocated, 'customer']], $this->audited(ClientApiAuditAction::SubjectAllocated));
        $replay = $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'support', 'scopeKey' => 'org-b'] + $this->body(3), $token, ['HTTP_IDEMPOTENCY_KEY' => 'e-1']);
        self::assertSame($alone['entry']['id'] ?? null, $this->field($replay, 'entry', 'id'));
        $mismatch = $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => 'c-2'], 'activityTypeKey' => 'support', 'scopeKey' => 'org-b'] + $this->body(3), $token, ['HTTP_IDEMPOTENCY_KEY' => 'e-1']);
        self::assertSame([422, 'idempotency_mismatch'], [$this->client->getResponse()->getStatusCode(), $mismatch['error'] ?? null], 'the body hash covers the subject');

        $both = $this->api('POST', '/api/time-track/entries', ['taskId' => 'task-1', 'subject' => ['type' => 'customer', 'id' => 'c-2']] + $this->body(4), $token, ['HTTP_IDEMPOTENCY_KEY' => 'e-2']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['task-1', 'customer', true], [$both['entry']['taskId'] ?? null, $both['entry']['subject']['type'] ?? null, $both['entry']['subject']['hidden'] ?? null]);
        self::assertArrayNotHasKey('id', $both['entry']['subject'] ?? []);

        foreach ([
            [['subject' => ['type' => 'customer', 'id' => 'c-404']], 403, 'subject_forbidden'],
            [['subject' => ['type' => 'vendor', 'id' => 'c-1']], 403, 'subject_forbidden'],
            [['subject' => ['type' => 'customer', 'id' => 'c-1'], 'scopeKey' => 'org-z'], 403, 'subject_forbidden'],
            [['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'legacy'], 422, 'activity_type_inactive'],
            [['subject' => ['type' => 'customer']], 400, 'invalid_request'],
            [['subject' => ['type' => 'customer', 'id' => 'c-1'], 'scopeKey' => ''], 400, 'invalid_request'],
            [['taskId' => 7, 'subject' => ['type' => 'customer', 'id' => 'c-1']], 400, 'invalid_request'],
            [[], 400, 'invalid_request'],
        ] as $i => [$extra, $status, $code]) {
            $answer = $this->api('POST', '/api/time-track/entries', $extra + $this->body(5 + $i), $token, ['HTTP_IDEMPOTENCY_KEY' => 'bad-' . $i]);
            self::assertSame([$status, $code], [$this->client->getResponse()->getStatusCode(), $answer['error'] ?? null], (string) $i);
        }
    }

    public function testReadSurfacesMaskASubjectTheViewerCannotRead(): void
    {
        $writeToken                  = $this->token(ClientScope::EntriesWrite, ClientScope::TimerWrite);
        $readToken                   = $this->token(ClientScope::EntriesRead, ClientScope::TimerRead);
        $this->customers()->loggable = ['c-1', 'c-9'];
        $this->customers()->readable = ['c-1'];
        $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => 'c-1'], 'activityTypeKey' => 'dev'] + $this->body(2), $writeToken, ['HTTP_IDEMPOTENCY_KEY' => 'm-1']);
        $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => 'c-9'], 'activityTypeKey' => 'retired-or-gone'] + $this->body(3), $writeToken, ['HTTP_IDEMPOTENCY_KEY' => 'm-2']);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', '/api/time-track/entries', ['subject' => ['type' => 'customer', 'id' => 'c-9'], 'activityTypeKey' => 'meeting'] + $this->body(3), $writeToken, ['HTTP_IDEMPOTENCY_KEY' => 'm-3']);
        $this->api('POST', '/api/time-track/entries', $this->body(4) + ['taskId' => 'task-2'], $writeToken, ['HTTP_IDEMPOTENCY_KEY' => 'm-4']);
        $this->api('POST', '/api/time-track/timer/start', ['subject' => ['type' => 'customer', 'id' => 'c-9']], $writeToken);

        $hidden  = ['type' => 'customer', 'hidden' => true, 'label' => 'Subject not visible'];
        $visible = ['type' => 'customer', 'id' => 'c-1', 'label' => 'customer c-1', 'url' => 'https://crm.example.test/customer/c-1'];
        $entries = $this->api('GET', '/api/time-track/entries', null, $readToken)['entries'] ?? [];
        self::assertSame([$visible, $hidden, null], array_column($entries, 'subject'));
        self::assertSame([['key' => 'dev', 'label' => 'Development'], ['key' => 'meeting', 'label' => 'Meeting'], null], array_column($entries, 'activityType'), 'the activity type stays visible');
        self::assertSame($hidden, $this->api('GET', '/api/time-track/timer', null, $readToken)['timer']['subject'] ?? null);
        self::assertSame([$visible, $hidden, null], array_column($this->api('GET', '/api/time-track/export', null, $readToken)['entries'] ?? [], 'subject'));
        self::assertSame(['actor' => (string) $this->user->getId()], ['actor' => $this->customers()->describeCalls[0]['actor'] ?? null], 'labelled with the viewer as actor');

        $this->client->request('GET', '/api/time-track/export?format=csv', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $readToken]);
        self::assertResponseIsSuccessful();
        $rows = array_values(array_filter(explode("\n", trim((string) $this->client->getResponse()->getContent()))));
        self::assertStringEndsWith('subject_type,subject_id,subject_label,activity_type_key,activity_type_label', $rows[0]);
        self::assertStringEndsWith('customer,c-1,"customer c-1",dev,Development', $rows[1]);
        self::assertStringEndsWith('customer,,"Subject not visible",meeting,Meeting', $rows[2], 'no id when masked');
        self::assertStringNotContainsString(',c-9,', (string) $this->client->getResponse()->getContent(), 'entry ids are UUIDs: match the cell, not the bare id');

        // A provider that throws while labelling: masked, never a 500.
        $this->customers()->fail = true;
        self::assertSame([$hidden, $hidden, null], array_column($this->api('GET', '/api/time-track/entries', null, $readToken)['entries'] ?? [], 'subject'));
    }

    /**
     * Audit rows of one action as [action, reason].
     *
     * @return list<array{0: ClientApiAuditAction, 1: string|null}>
     */
    private function audited(ClientApiAuditAction $action): array
    {
        return array_values(array_map(static fn (ClientApiEvent $event): array => [$event->action, $event->reason], array_filter($this->audit, static fn (ClientApiEvent $event): bool => $event->action === $action)));
    }

    /**
     * The value at the path of a decoded body (which must hold it, null included).
     */
    private function field(mixed $body, string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($body);
            self::assertArrayHasKey($key, $body);
            $body = $body[$key];
        }

        return $body;
    }

    /**
     * A one-hour manual entry body `$daysAgo` days ago (without a task: add `taskId`).
     *
     * @return array<string, string>
     */
    private function body(int $daysAgo): array
    {
        $start = (new DateTimeImmutable('today -' . $daysAgo . ' days'))->setTime(9, 0);

        return ['startedAt' => $start->format(DATE_ATOM), 'endedAt' => $start->modify('+1 hour')->format(DATE_ATOM), 'source' => 'manual'];
    }

    /**
     * The `customer` provider of the kernel.
     */
    private function customers(): CustomerTestSubjectProvider
    {
        $provider = self::getContainer()->get(CustomerTestSubjectProvider::class);
        self::assertInstanceOf(CustomerTestSubjectProvider::class, $provider);

        return $provider;
    }

    /**
     * The `internal` provider of the kernel.
     */
    private function internals(): InternalTestSubjectProvider
    {
        $provider = self::getContainer()->get(InternalTestSubjectProvider::class);
        self::assertInstanceOf(InternalTestSubjectProvider::class, $provider);

        return $provider;
    }

    /**
     * The activity type provider of the kernel.
     */
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
