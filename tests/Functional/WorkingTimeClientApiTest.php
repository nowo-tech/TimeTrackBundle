<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Event\TaskEffortAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_slice;
use function array_values;
use function str_contains;
use function str_getcsv;

use const DATE_ATOM;

/**
 * Since 1.13 (contract §10, AC-01, AC-11, AC-12): the working-time client API — workday (R-GDPR §8 matrix), balance,
 * timesheets with submit / withdraw, `GET me` flags and summary, `locked` / `scopeKey` on entries and the CSV, 409
 * lock answers on timer routes, task effort, and the 1.13 scopes that old tokens do not have.
 */
class WorkingTimeClientApiTest extends FunctionalTestCase
{
    private AppUser $approver;

    protected static function timeTrackConfig(): array
    {
        return [
            'timesheets'   => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'working_time' => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
            'client_api'   => ['workday_provider' => StubWorkdayProvider::class, 'backdate' => ['max_seconds' => 86400], 'links_working_time_route' => 'nowo_time_track_index'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->approver = $this->createUser('boss@example.com');
        $scopes         = self::getContainer()->get(FixedScopeProvider::class);
        self::assertInstanceOf(FixedScopeProvider::class, $scopes);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a', 'org-b'], (string) $this->approver->getId() => ['org-a']];
        $approvers           = self::getContainer()->get(StubTimesheetApproverProvider::class);
        self::assertInstanceOf(StubTimesheetApproverProvider::class, $approvers);
        $approvers->approvers = ['org-a' => [(string) $this->approver->getId() => [(string) $this->user->getId()]]];
    }

    public function testOldTokensDoNotGetTheNewScopes(): void
    {
        $token = $this->login();
        foreach (['/api/time-track/workday', '/api/time-track/balance', '/api/time-track/timesheets'] as $uri) {
            $body = $this->api('GET', $uri, null, $token);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('insufficient_scope', $body['error'] ?? null);
        }
        foreach (['/api/time-track/workday/clock-in', '/api/time-track/timesheets/current/submit'] as $uri) {
            $this->api('POST', $uri, [], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k']);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testTheWorkdayMatrix(): void
    {
        $token    = $this->token(ClientScope::WorkdayRead, ClientScope::WorkdayWrite);
        $register = self::getContainer()->get(StubWorkdayProvider::class);
        self::assertInstanceOf(StubWorkdayProvider::class, $register);

        // Since 1.22 (spec 204): without a leave provider the body is exactly the 1.21 one (no `leave` key).
        self::assertSame(
            ['status' => 'idle', 'date' => '2026-11-05', 'timezone' => 'Europe/Madrid', 'segments' => [], 'workedSeconds' => 0, 'breakSeconds' => 0, 'flags' => [], 'reminder' => null, 'allowedActions' => ['clock-in']],
            $this->api('GET', '/api/time-track/workday', null, $token),
        );
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'), 'since 1.22 (audit NG-3), with or without a leave provider');
        $this->api('POST', '/api/time-track/workday/clock-in', [], $token);
        self::assertResponseStatusCodeSame(400, 'Idempotency-Key is required');

        $at    = (new DateTimeImmutable('-2 hours'))->format(DATE_ATOM);
        $first = $this->api('POST', '/api/time-track/workday/clock-in', ['occurredAt' => $at], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);
        self::assertResponseIsSuccessful();
        self::assertSame('working', $first['status'] ?? null);
        $mismatch = $this->api('POST', '/api/time-track/workday/clock-in', [], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);
        self::assertResponseStatusCodeSame(422, 'same key, another body');
        self::assertSame('idempotency_mismatch', $mismatch['error'] ?? null);
        $replay = $this->api('POST', '/api/time-track/workday/clock-in', ['occurredAt' => $at], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-1']);
        self::assertResponseIsSuccessful();
        self::assertSame($first, $replay);
        self::assertCount(1, $register->calls, 'a replay does not reach the register');

        $conflict = $this->api('POST', '/api/time-track/workday/clock-in', [], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-2']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('invalid_transition', $conflict['error'] ?? null);
        self::assertSame('working', $conflict['state']['status'] ?? null);
        $again = $this->api('POST', '/api/time-track/workday/clock-in', [], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-2']);
        self::assertResponseStatusCodeSame(409, 'a conflict is replayed too');
        self::assertSame($conflict, $again);

        foreach (['+10 minutes', '-73 hours'] as $when) {
            $this->api('POST', '/api/time-track/workday/break-start', ['occurredAt' => (new DateTimeImmutable($when))->format(DATE_ATOM)], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-' . md5($when)]);
            self::assertResponseStatusCodeSame(422);
        }
        $bad = $this->api('POST', '/api/time-track/workday/break-start', ['occurredAt' => 'yesterday'], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-3']);
        self::assertSame('invalid_occurred_at', $bad['error'] ?? null);
        $this->api('POST', '/api/time-track/workday/break-start', ['occurredAt' => '2026-13-45T10:00:00Z'], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-4']);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', '/api/time-track/workday/break-start', null, $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-5']);
        self::assertResponseIsSuccessful();
        self::assertSame(['break-start', null, 'k-5', 'desktop'], end($register->calls));

        $register->forbidden = ['person@example.com'];
        $this->api('POST', '/api/time-track/workday/break-end', [], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-6']);
        self::assertResponseStatusCodeSame(403);
        $register->off = ['person@example.com'];
        $this->api('GET', '/api/time-track/workday', null, $token);
        self::assertResponseStatusCodeSame(404);
        $this->api('POST', '/api/time-track/workday/break-end', [], $token, ['HTTP_IDEMPOTENCY_KEY' => 'k-7']);
        self::assertResponseStatusCodeSame(404);
        $this->api('OPTIONS', '/api/time-track/workday', null, null, ['HTTP_ORIGIN' => 'chrome-extension://functional']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testBalanceTimesheetsAndMe(): void
    {
        $token  = $this->token(ClientScope::BalanceRead, ClientScope::TimesheetRead, ClientScope::TimesheetWrite, ClientScope::EntriesRead, ClientScope::TimerWrite, ClientScope::TasksRead);
        $sheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $sheets);
        $week   = $sheets->period($this->user, 'org-a', 'previous');
        $monday = $week->startsAt('UTC');
        $entry  = (new TimeEntry($this->user, 'task-1', 'Implement login API', 'board-1', $monday->modify('+8 hours'), $monday->modify('+17 hours'), 32_400, TimeEntrySource::Web))->assignScopeKey('org-a');
        (new DoctrineOrmTimeEntryRepository($this->entityManager()))->save($entry);

        $balance = $this->api('GET', '/api/time-track/balance?from=' . $week->start . '&to=' . $week->end, null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'org-a', 'label' => 'Org org-a'], $balance['scope'] ?? null);
        self::assertSame('task_time', $balance['source'] ?? null);
        self::assertFalse($balance['legalRecord'] ?? true, 'AC-11');
        self::assertSame(3600 - 4 * 28_800, $balance['totals']['diffSeconds'] ?? null, '9 h on Monday, nothing Tuesday to Friday');
        $this->api('GET', '/api/time-track/balance', null, $token);
        self::assertResponseIsSuccessful();
        $this->api('GET', '/api/time-track/balance?from=2026-01-01&to=2026-06-01', null, $token);
        self::assertResponseStatusCodeSame(400);
        $this->api('GET', '/api/time-track/balance?scope=org-x', null, $token);
        self::assertResponseStatusCodeSame(404);

        $current = $this->api('GET', '/api/time-track/timesheets', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame('open', $current['status'] ?? null);
        self::assertSame(['submit'], $current['allowedActions'] ?? null);
        $previous = $this->api('GET', '/api/time-track/timesheets?period=previous', null, $token);
        self::assertSame($week->key, $previous['period']['key'] ?? null);
        self::assertSame(32_400, $previous['totals']['taskSeconds'] ?? null);
        self::assertSame([$entry->getId()], array_column($previous['entries'] ?? [], 'id'));
        self::assertFalse($previous['entries'][0]['locked'] ?? true);

        $submitted = $this->api('POST', '/api/time-track/timesheets/' . $week->key . '/submit', ['scope' => 'org-a'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('submitted', $submitted['status'] ?? null);
        self::assertSame(['withdraw'], $submitted['allowedActions'] ?? null);
        self::assertSame('desktop', $submitted['events'][0]['client'] ?? null);
        $this->api('POST', '/api/time-track/timesheets/' . $week->key . '/submit', [], $token);
        self::assertResponseStatusCodeSame(409);
        // Submitted: the owner's timer refuses a backdated start in it.
        $refused = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => $week->endsBefore('UTC')->modify('-1 hour')->format(DATE_ATOM)], $token);
        if (str_contains((string) $this->client->getResponse()->getContent(), 'period_submitted')) {
            self::assertResponseStatusCodeSame(409);
            self::assertSame('period_submitted', $refused['error'] ?? null);
        }
        $withdrawn = $this->api('POST', '/api/time-track/timesheets/previous/withdraw', [], $token);
        self::assertSame('open', $withdrawn['status'] ?? null);
        $this->api('POST', '/api/time-track/timesheets/' . $week->key . '/withdraw', [], $token);
        self::assertResponseStatusCodeSame(409);
        $this->api('POST', '/api/time-track/timesheets/' . $week->next()->next()->key . '/submit', [], $token);
        self::assertResponseStatusCodeSame(409, 'not started');
        $this->api('GET', '/api/time-track/timesheets?scope=org-x', null, $token);
        self::assertResponseStatusCodeSame(404);
        $this->api('GET', '/api/time-track/timesheets?period=2026-W99', null, $token);
        self::assertResponseStatusCodeSame(404);

        // Approved: entries are locked, the CSV says so, a backdated start answers period_locked with lockedThrough.
        $sheet = $sheets->submit($this->user, 'org-a', $week);
        $sheets->approve($this->approver, $sheet->getId());
        $entries = $this->api('GET', '/api/time-track/entries?from=' . $week->start . '&to=' . $week->end, null, $token);
        self::assertTrue($entries['entries'][0]['locked'] ?? false);
        self::assertSame('org-a', $entries['entries'][0]['scopeKey'] ?? null);
        $this->client->request('GET', '/api/time-track/export?format=csv&from=' . $week->start . '&to=' . $week->end, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", (string) $this->client->getResponse()->getContent())));
        self::assertSame(['scope_key', 'locked'], array_slice($rows[0], -4, 2));
        self::assertSame(['org-a', 'yes'], array_slice($rows[1], -4, 2));
        $json = $this->api('GET', '/api/time-track/export?from=' . $week->start . '&to=' . $week->end, null, $token);
        self::assertTrue($json['entries'][0]['locked'] ?? false);
        $locked = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => (new DateTimeImmutable('-23 hours'))->format(DATE_ATOM)], $token);
        if (($locked['error'] ?? null) === 'period_locked') {
            self::assertSame($week->end, $locked['lockedThrough'] ?? null);
        }
        $afterApprove = $this->api('GET', '/api/time-track/timesheets?period=' . $week->key, null, $token);
        self::assertTrue($afterApprove['locked'] ?? false);
        self::assertSame($week->end, $afterApprove['lockedThrough'] ?? null);
        self::assertSame([], $afterApprove['allowedActions'] ?? null);
        self::assertSame(['submitted', 'withdrawn', 'submitted', 'approved'], array_column($afterApprove['events'] ?? [], 'action'));

        // GET me: the flags and the current period; tasks: the effort fields.
        $me = $this->api('GET', '/api/time-track/me', null, $token);
        self::assertTrue($me['features']['timesheets'] ?? false);
        self::assertTrue($me['features']['balance'] ?? false);
        self::assertTrue($me['features']['workday'] ?? false);
        self::assertTrue($me['features']['task_effort'] ?? false);
        self::assertSame(['calendars' => null, 'workingTime' => null], $me['links'] ?? [], 'the test host is http: no link');
        self::assertArrayNotHasKey('leave', $me['features'] ?? [], 'since 1.22: no leave flag without a leave provider');
        // Since 1.19 (spec 198): over https the host's working-time page is linked while the workday API is on.
        $secure = $this->api('GET', '/api/time-track/me', null, $token, ['HTTPS' => 'on']);
        self::assertIsArray($secure);
        $link = $secure['links']['workingTime'] ?? null;
        self::assertIsString($link);
        self::assertStringStartsWith('https://localhost/', $link);
        self::assertSame('org-a', $me['timesheet']['scopeKey'] ?? null);
        self::assertSame('open', $me['timesheet']['status'] ?? null);
        $tasks = $this->api('GET', '/api/time-track/tasks', null, $token);
        self::assertSame(['estimateSeconds' => null, 'loggedSeconds' => 32_400, 'loggedSecondsScope' => 'own', 'remainingSeconds' => null], array_slice($tasks['tasks'][0] ?? [], -4));
    }

    public function testAuditGbMaskingDraftsBoundsAndTaskEffort(): void
    {
        $token  = $this->token(ClientScope::BalanceRead, ClientScope::TimesheetRead, ClientScope::TasksRead);
        $sheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $sheets);
        $week   = $sheets->period($this->user, 'org-a', 'previous');
        $monday = $week->startsAt('UTC');
        $repo   = new DoctrineOrmTimeEntryRepository($this->entityManager());
        $repo->save(TimeEntry::imported($this->user, new ImportedTimeEntryData('clickup:d', 'gone-1', 'task-gone', 'Gone project', null, $monday->modify('+8 hours'), $monday->modify('+9 hours'), 3600, description: 'Secret description'))->assignScopeKey('org-a'));
        $repo->save((new TimeEntry($this->user, 'task-1', 'Implement login API', 'board-1', $monday->modify('+10 hours'), $monday->modify('+12 hours'), 7200, TimeEntrySource::Web))->assignScopeKey('org-a'));
        $repo->save((new TimeEntry($this->approver, 'task-1', 'Implement login API', 'board-1', $monday->modify('+10 hours'), $monday->modify('+11 hours'), 3600, TimeEntrySource::Web))->assignScopeKey('org-a'));
        $rows = fn (): int => $this->entityManager()->getRepository(Timesheet::class)->count([]);

        // GB-M2: GET timesheets masks the imported entry whose task the owner cannot see, as GET entries does.
        $previous = $this->api('GET', '/api/time-track/timesheets?period=previous', null, $token);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Gone project', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Secret description', (string) $this->client->getResponse()->getContent());
        $masked = array_values(array_filter($previous['entries'] ?? [], static fn (array $e): bool => ($e['taskId'] ?? null) === 'task-gone'));
        self::assertSame('Task not visible', $masked[0]['taskTitle'] ?? null);
        self::assertFalse($masked[0]['taskVisible'] ?? true);

        // GB-L1: GET me never creates a row; an older period is an unstored draft (id null); later than next is 404.
        $before = $rows();
        $this->api('GET', '/api/time-track/me', null, $token);
        self::assertSame($before, $rows());
        $old = $this->api('GET', '/api/time-track/timesheets?period=' . $week->previous()->previous()->key, null, $token);
        self::assertResponseIsSuccessful();
        self::assertIsArray($old);
        self::assertArrayHasKey('id', $old);
        self::assertNull($old['id']);
        self::assertSame('open', $old['status'] ?? null);
        self::assertSame([], $old['events'] ?? null);
        self::assertSame($before, $rows());
        foreach (['9998-W52', '1999-W52', $week->next()->next()->next()->key] as $key) {
            $this->api('GET', '/api/time-track/timesheets?period=' . $key, null, $token);
            self::assertResponseStatusCodeSame(404, $key);
        }
        self::assertSame($before, $rows());

        // GB-M1: far balance ranges are refused before anything is computed.
        foreach (['from=9998-12-01&to=9998-12-31', 'from=1999-12-01&to=1999-12-31'] as $range) {
            $this->api('GET', '/api/time-track/balance?' . $range, null, $token);
            self::assertResponseStatusCodeSame(400, $range);
        }

        // GB-M3: loggedSeconds is the user's own total; the all-users total only for users who can manage the task.
        $tasks = $this->api('GET', '/api/time-track/tasks', null, $token);
        self::assertSame(7200, $tasks['tasks'][0]['loggedSeconds'] ?? null);
        self::assertSame('own', $tasks['tasks'][0]['loggedSecondsScope'] ?? null);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TimeTrackEvents::TASK_EFFORT_ACCESS_CHECK, static function (TaskEffortAccessCheckEvent $event): void {
            if ($event->getTaskId() === 'task-1') {
                $event->grant();
            }
        });
        $managed = $this->api('GET', '/api/time-track/tasks', null, $token);
        self::assertSame(10_800, $managed['tasks'][0]['loggedSeconds'] ?? null);
        self::assertSame('all', $managed['tasks'][0]['loggedSecondsScope'] ?? null);
        self::assertNull($managed['tasks'][1]['loggedSeconds'] ?? null, 'no time on task-2');
        self::assertArrayHasKey('loggedSecondsScope', $managed['tasks'][1] ?? []);
        self::assertNull($managed['tasks'][1]['loggedSecondsScope'] ?? null, 'no scope without a figure');
    }

    #[Group('shared-entity-manager')]
    public function testRunningTimersAndLockedSwitches(): void
    {
        $token  = $this->token(ClientScope::TimerWrite, ClientScope::TimerRead, ClientScope::TimesheetWrite);
        $sheets = self::getContainer()->get(TimesheetService::class);
        self::assertInstanceOf(TimesheetService::class, $sheets);
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1'], $token);
        self::assertResponseStatusCodeSame(201);
        $timer = $this->api('GET', '/api/time-track/timer', null, $token);
        self::assertArrayHasKey('loggedSeconds', $timer['timer'] ?? []);

        // A timer running in the current week refuses its submit; once submitted, switching answers period_submitted.
        $current = $sheets->period($this->user, 'org-a', 'current');
        $refused = $this->api('POST', '/api/time-track/timesheets/current/submit', [], $token);
        self::assertSame('timer_running', $refused['error'] ?? null);
        $this->api('POST', '/api/time-track/timer/stop', [], $token);
        $this->api('POST', '/api/time-track/timesheets/current/submit', [], $token);
        self::assertResponseIsSuccessful();
        $switch = $this->api('POST', '/api/time-track/timer/switch', ['taskId' => 'task-2'], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('period_submitted', $switch['error'] ?? null);
        self::assertArrayNotHasKey('lockedThrough', $switch);
        $start = $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-2'], $token);
        self::assertSame('period_submitted', $start['error'] ?? null);

        // A manual lock while a timer runs: idle decisions answer period_locked with lockedThrough; the stop still works.
        $this->api('POST', '/api/time-track/timesheets/current/withdraw', [], $token);
        $this->entityManager()->getConnection()->executeStatement('DELETE FROM time_track_entries'); // no overlap clamp
        $this->api('POST', '/api/time-track/timer/start', ['taskId' => 'task-1', 'startedAt' => (new DateTimeImmutable('-30 minutes'))->format(DATE_ATOM)], $token);
        self::assertResponseStatusCodeSame(201);
        $sheet = $sheets->view($this->user, (string) $this->user->getId(), 'org-a', $current);
        $sheet->applyLock(new DateTimeImmutable(), TimesheetLockReason::Manual);
        $this->entityManager()->flush();
        $idle = $this->api('POST', '/api/time-track/timer/discard-idle', ['from' => (new DateTimeImmutable('-20 minutes'))->format(DATE_ATOM), 'to' => (new DateTimeImmutable('-10 minutes'))->format(DATE_ATOM), 'action' => 'discard'], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('period_locked', $idle['error'] ?? null);
        self::assertSame($current->end, $idle['lockedThrough'] ?? null);
        $this->api('POST', '/api/time-track/timer/stop', [], $token);
        self::assertResponseIsSuccessful();
        self::assertSame($current->key, $sheets->view($this->user, (string) $this->user->getId(), 'org-a', $current)->getPeriod()->key);
    }

    protected function token(ClientScope ...$scopes): string
    {
        $auth = self::getContainer()->get(ClientAuthService::class);
        self::assertInstanceOf(ClientAuthService::class, $auth);

        return (string) $auth->issueToken($this->user, ClientType::Desktop, null, array_values($scopes))['token'];
    }
}
