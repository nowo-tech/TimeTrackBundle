<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entry\ManualTimeEntryDraft;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\PersonalData\TimeEntryErasure;
use Nowo\TimeTrackBundle\PersonalData\TimeTrackPersonalDataInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Query\AllocationFilter;
use Nowo\TimeTrackBundle\Query\RunningTimerQueryInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Query\TimeEntryGrouping;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Query\TimeEntryTotal;
use Nowo\TimeTrackBundle\Query\TimeEntryTotals;
use Nowo\TimeTrackBundle\Query\TimeEntryTotalsQueryInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\TimeAllocation;
use Nowo\TimeTrackBundle\Subject\TimeAllocationPatch;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\RecordingTaskAccessChecker;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\CustomerTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedActivityTypeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Tests\Support\InternalTestSubjectProvider;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timer\HostTimerApi;
use Symfony\Component\HttpKernel\KernelInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function sort;
use function str_starts_with;

/**
 * Since 2.1.0 (Beacon spec 256, design §7, §8, §10) on the Doctrine stores (SQLite here; MySQL 8.4, MariaDB 11.4 and
 * PostgreSQL 16 in the database jobs): corrections and manual entries through {@see TimeEntryOperationsInterface} with
 * an allocation, {@see HostTimerApi::startAllocated()}, the allocation filters of the query port, the totals port (one
 * statement, bounded), and the GDPR export and erasure of the three columns.
 */
final class AllocationDatabaseTest extends FunctionalTestCase
{
    private AppUser $other;

    private CustomerTestSubjectProvider $customers;

    private InternalTestSubjectProvider $internal;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return [
            'manual_entries' => ['enabled' => true],
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'entries'        => ['allow_without_task' => true],
            'client_api'     => ['task_access_checker' => RecordingTaskAccessChecker::class],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->other         = $this->createUser('other@example.com');
        $scopes              = $this->service(FixedScopeProvider::class);
        $scopes->memberships = [(string) $this->user->getId() => ['org-a', 'org-b'], (string) $this->other->getId() => ['org-a']];
        $this->customers     = $this->service(CustomerTestSubjectProvider::class);
        $this->internal      = $this->service(InternalTestSubjectProvider::class);
        $this->customers->reset();
        $this->internal->reset();
        $this->service(FixedActivityTypeProvider::class)->reset();
        $this->customers->loggable                                 = ['c-1', 'c-2'];
        $this->internal->loggable                                  = ['i-1'];
        $this->service(RecordingTaskAccessChecker::class)->checked = [];
    }

    public function testAManualEntryWithAnAllocationThroughTheOperationsPort(): void
    {
        $start  = $this->at('-1 day 08:00');
        $record = $this->operations()->createManual($this->id($this->user), new ManualTimeEntryDraft(null, $start, $start->modify('+1 hour'), allocation: TimeAllocation::task('task-3', $this->customer('c-1'), 'dev')));

        self::assertSame(['task-3', 'org-b', 'c-1', 'dev', true], [$record->entry->taskId, $record->entry->scopeKey, $record->entry->subject?->id, $record->entry->activityTypeKey, $record->billable]);
        self::assertSame([['actor' => $this->id($this->user), 'scope' => 'org-b', 'id' => 'c-1']], $this->customers->canLogCalls, 'the owner, in the task\'s scope');
        self::assertSame($record->version(), $this->query()->find($record->entry->id)?->version());

        $subjectOnly = $this->operations()->createManual($this->id($this->user), new ManualTimeEntryDraft(null, $start->modify('+2 hours'), $start->modify('+3 hours'), scopeKey: 'org-a', allocation: TimeAllocation::subject($this->internal('i-1'), 'meeting')));
        self::assertSame([true, 'org-a', 'internal', null], [$subjectOnly->entry->withoutTask, $subjectOnly->entry->scopeKey, $subjectOnly->entry->subject?->type, $subjectOnly->billable]);

        try {
            $this->operations()->createManual($this->id($this->user), new ManualTimeEntryDraft(null, $start->modify('+4 hours'), $start->modify('+5 hours'), allocation: TimeAllocation::task('task-1', $this->internal('i-1'))));
            self::fail('expected allocation_conflict');
        } catch (TimeAllocationException $exception) {
            self::assertSame(TimeAllocationException::ALLOCATION_CONFLICT, $exception->errorCode);
        }
        $this->expectException(InvalidArgumentException::class);
        new ManualTimeEntryDraft('task-2', $start, $start->modify('+1 hour'), allocation: TimeAllocation::task('task-1'));
    }

    public function testACorrectionChecksTheSubjectOnlyWhenItChanges(): void
    {
        $id      = $this->manual($this->user, TimeAllocation::subject($this->customer('c-1'), 'dev'), '-2 days 09:00', 3600, 'org-a');
        $version = $this->version($id);
        $asked   = count($this->customers->canLogCalls);

        // The owner lost access to c-1: a times-only fix stays possible (Q-TS-8), keeping the same subject is no change.
        $this->customers->loggable = ['c-2'];
        $times                     = $this->operations()->correct($this->id($this->user), $id, $version, (new TimeEntryCorrection(durationSeconds: 1800))->withAllocation(TimeAllocationPatch::keep()->withSubject($this->customer('c-1'))->withActivityType('dev')));
        self::assertSame(['duration_seconds'], $times->changedFields);
        self::assertCount($asked, $this->customers->canLogCalls, 'canLog is not asked again');
        self::assertSame(['c-1', 'dev'], [$times->after->entry->subject?->id, $times->after->entry->activityTypeKey]);

        // A change of the subject is checked: refused before anything is written.
        foreach (['c-1-x' => TimeAllocationException::SUBJECT_FORBIDDEN] as $subjectId => $code) {
            try {
                $this->operations()->correct($this->id($this->user), $id, $times->after->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject($this->customer($subjectId))));
                self::fail('expected ' . $code);
            } catch (TimeAllocationException $exception) {
                self::assertSame($code, $exception->errorCode);
            }
        }
        foreach (['nope' => TimeAllocationException::ACTIVITY_TYPE_UNKNOWN, 'legacy' => TimeAllocationException::ACTIVITY_TYPE_INACTIVE] as $key => $code) {
            try {
                $this->operations()->correct($this->id($this->user), $id, $times->after->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withActivityType($key)));
                self::fail('expected ' . $code);
            } catch (TimeAllocationException $exception) {
                self::assertSame($code, $exception->errorCode);
            }
        }
        self::assertSame($times->after->version(), $this->version($id));

        // Everything at once: the fields in their documented order, the version and both records.
        $start  = $this->at('-2 days 08:00');
        $result = $this->operations()->correct($this->id($this->user), $id, $times->after->version(), TimeEntryCorrection::times($start, $start->modify('+45 minutes'))->withNote('fixed')->withAllocation(TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))->withActivityType('support')));
        self::assertSame(['started_at', 'ended_at', 'duration_seconds', 'metadata', 'subject', 'activity_type_key'], $result->changedFields);
        self::assertSame(['c-1', 'dev', 'c-2', 'support'], [$result->before->entry->subject?->id, $result->before->entry->activityTypeKey, $result->after->entry->subject?->id, $result->after->entry->activityTypeKey]);
        self::assertNotSame($times->after->version(), $result->after->version());
        self::assertSame($result->after->version(), $this->version($id));
        self::assertSame(['org-a', 'c-2'], [$this->customers->canLogCalls[count($this->customers->canLogCalls) - 1]['scope'], $this->customers->canLogCalls[count($this->customers->canLogCalls) - 1]['id']]);

        // An allocation change alone changes the version; the stale version is a conflict.
        $cleared = $this->operations()->correct($this->id($this->user), $id, $result->after->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withoutActivityType()));
        self::assertSame(['activity_type_key'], $cleared->changedFields);
        self::assertNotSame($result->after->version(), $cleared->after->version());
        try {
            $this->operations()->correct($this->id($this->user), $id, $result->after->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withActivityType('dev')));
            self::fail('expected conflict');
        } catch (TimeEntryCorrectionException $exception) {
            self::assertSame(TimeEntryCorrectionException::CONFLICT, $exception->errorCode);
        }

        // Clearing the subject of a subject-alone entry (allow_without_task on, require_allocation off): unallocated.
        $none = $this->operations()->correct($this->id($this->user), $id, $cleared->after->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withoutSubject()));
        self::assertSame([['subject'], null], [$none->changedFields, $none->after->entry->subject]);
    }

    public function testATaskEntryKeepsItsTaskAndTakesOnlySubjectsThatAllowTasks(): void
    {
        $id      = $this->manual($this->user, TimeAllocation::task('task-1', null, 'dev'), '-1 day 10:00', 1200);
        $version = $this->version($id);
        try {
            $this->operations()->correct($this->id($this->user), $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject($this->internal('i-1'))));
            self::fail('expected allocation_conflict');
        } catch (TimeAllocationException $exception) {
            self::assertSame(TimeAllocationException::ALLOCATION_CONFLICT, $exception->errorCode);
        }
        $set = $this->operations()->correct($this->id($this->user), $id, $version, TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))));
        self::assertSame(['task-1', 'c-2', ['subject']], [$set->after->entry->taskId, $set->after->entry->subject?->id, $set->changedFields]);

        // Clearing the subject of a task entry is always allowed.
        $clear = $this->operations()->correct($this->id($this->user), $id, $set->after->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()->withoutSubject()));
        self::assertSame(['task-1', null], [$clear->after->entry->taskId, $clear->after->entry->subject]);
        // A keep patch changes nothing.
        self::assertSame([], $this->operations()->correct($this->id($this->user), $id, $clear->after->version(), TimeEntryCorrection::allocation(TimeAllocationPatch::keep()))->changedFields);
    }

    public function testTheHostTimerApiStartsOnAnAllocationAndStopsWithAPatch(): void
    {
        $api   = $this->service(HostTimerApi::class);
        $actor = TimeTrackUserRefFactory::build($this->user);

        // Task access first: a refused task never reaches canLog.
        try {
            $api->startAllocated($actor, TimeAllocation::task('task-2', $this->customer('c-1')));
            self::fail('expected task refusal');
        } catch (TaskNotTrackableException) {
            self::assertSame([], $this->customers->canLogCalls);
        }
        try {
            $api->startAllocated($actor, TimeAllocation::task('task-1', $this->customer('c-9')));
            self::fail('expected subject_forbidden');
        } catch (TimeAllocationException $exception) {
            self::assertSame(TimeAllocationException::SUBJECT_FORBIDDEN, $exception->errorCode);
        }
        self::assertNull($api->current($actor));

        $started = $api->startAllocated($actor, TimeAllocation::task('task-1', $this->customer('c-1'), 'dev'), 'kick-off', 'mcp');
        self::assertSame(['task-1', 'c-1', 'dev', 'mcp'], [$started->timer->taskId, $started->timer->subject?->id, $started->timer->activityTypeKey, $started->timer->channel]);
        $running = $this->service(RunningTimerQueryInterface::class)->runningOf($this->id($this->user));
        self::assertSame(['c-1', 'dev'], [$running?->timer->subject?->id, $running?->timer->activityTypeKey], 'the running timer view carries them');

        $replaced = $api->startAllocated($actor, TimeAllocation::subject($this->internal('i-1'), 'meeting'), null, 'api', 'org-b', 'Planning');
        self::assertSame('c-1', $replaced->stopped?->subject?->id);
        self::assertSame([true, 'org-b', 'i-1'], [$replaced->timer->withoutTask, $replaced->timer->scopeKey, $replaced->timer->subject?->id]);

        $stopped = $api->stop($actor, 'done', TimeAllocationPatch::keep()->withSubject($this->customer('c-2'))->withActivityType('support'));
        self::assertSame(['c-2', 'support', 'org-b'], [$stopped?->subject?->id, $stopped?->activityTypeKey, $stopped?->scopeKey]);
        self::assertNull($api->stop($actor));
    }

    public function testTheQueryPortFiltersByAllocation(): void
    {
        $ids = $this->fixtures();

        self::assertSame($this->sorted([$ids['e1'], $ids['e3'], $ids['e5']]), $this->listed(new TimeEntryCriteria(ownerIds: [$this->id($this->user)], subjects: [$this->customer('c-1'), $this->internal('i-1'), $this->customer('c-2')])));
        self::assertSame($this->sorted([$ids['e1'], $ids['e6']]), $this->listed(new TimeEntryCriteria(scopeKeys: ['org-a'], subjects: [$this->customer('c-1')])), 'task + subject and subject-alone entries alike');
        self::assertSame([], $this->listed(new TimeEntryCriteria(scopeKeys: ['org-a'], subjects: [new TimeSubjectRef('customer', 'C-1')])), 'byte for byte');
        self::assertSame([], $this->listed(new TimeEntryCriteria(scopeKeys: ['org-a'], subjects: [])), 'an empty list matches nothing');
        self::assertSame($this->sorted([$ids['e1'], $ids['e5']]), $this->listed(new TimeEntryCriteria(ownerIds: [$this->id($this->user)], subjectTypes: ['customer'])));
        self::assertSame($this->sorted([$ids['e1'], $ids['e3']]), $this->listed(new TimeEntryCriteria(ownerIds: [$this->id($this->user)], activityTypeKeys: ['dev', 'meeting'])));
        foreach ([
            [AllocationFilter::Any, ['e1', 'e2', 'e3', 'e4', 'e5']],
            [AllocationFilter::TaskOnly, ['e2']],
            [AllocationFilter::TaskAndSubject, ['e1']],
            [AllocationFilter::SubjectOnly, ['e3', 'e5']],
            [AllocationFilter::Unallocated, ['e4']],
        ] as [$filter, $expected]) {
            self::assertSame($this->sorted(array_map(static fn (string $key): string => $ids[$key], $expected)), $this->listed(new TimeEntryCriteria(ownerIds: [$this->id($this->user)], allocation: $filter)), $filter->value);
        }
        self::assertSame(2, $this->query()->count(new TimeEntryCriteria(ownerIds: [$this->id($this->user)], allocation: AllocationFilter::SubjectOnly)));
        self::assertSame(2100, $this->query()->totalSeconds(new TimeEntryCriteria(ownerIds: [$this->id($this->user)], allocation: AllocationFilter::SubjectOnly)));
        self::assertSame([$ids['e5']], array_map(static fn (TimeEntryRecord $record): string => $record->entry->id, $this->query()->listAfter(new TimeEntryCriteria(scopeKeys: ['org-b'], subjectTypes: ['customer']))));

        $record = $this->query()->find($ids['e1']);
        self::assertSame(['task-1', 'customer', 'c-1', 'dev'], [$record?->entry->taskId, $record?->entry->subject?->type, $record?->entry->subject?->id, $record?->entry->activityTypeKey]);
    }

    public function testTotalsGroupEveryWayInOneBoundedStatement(): void
    {
        $this->fixtures();
        $person = $this->id($this->user);
        $other  = $this->id($this->other);
        $mine   = $this->criteria(ownerIds: [$person]);

        self::assertSame([['customer|c-1', 3600, 1], ['|', 2400, 2], ['internal|i-1', 1200, 1], ['customer|c-2', 900, 1]], $this->grouped($mine, TimeEntryGrouping::Subject));
        self::assertSame([['customer', 4500, 2], ['', 2400, 2], ['internal', 1200, 1]], $this->grouped($mine, TimeEntryGrouping::SubjectType));
        self::assertSame([['dev', 3600, 1], ['support', 1800, 1], ['', 1500, 2], ['meeting', 1200, 1]], $this->grouped($mine, TimeEntryGrouping::ActivityType));
        self::assertSame([['customer|c-1|dev', 3600, 1], ['||support', 1800, 1], ['internal|i-1|meeting', 1200, 1], ['customer|c-2|', 900, 1], ['||', 600, 1]], $this->grouped($mine, TimeEntryGrouping::SubjectAndActivityType));
        self::assertSame([[$person, 8100, 5], [$other, 2400, 1]], $this->grouped($this->criteria(scopeKeys: ['org-a', 'org-b']), TimeEntryGrouping::Owner));
        self::assertSame([['task-1', 5400, 2], ['', 2700, 3]], $this->grouped($mine, TimeEntryGrouping::Task));
        self::assertSame([['task_subject', 3600, 1], ['subject', 2100, 2], ['task', 1800, 1], ['none', 600, 1]], $this->grouped($mine, TimeEntryGrouping::Allocation));
        self::assertSame([['customer|c-1', 6000, 2]], $this->grouped($this->criteria(scopeKeys: ['org-a'], subjects: [$this->customer('c-1')]), TimeEntryGrouping::Subject));
        self::assertSame([['customer', 3600, 1]], $this->grouped($this->criteria(ownerIds: [$person], subjectTypes: ['customer'], activityTypeKeys: ['dev', 'support', 'meeting']), TimeEntryGrouping::SubjectType), 'c-2 has no activity type');

        // The limit cuts the answer and says so, with no second query.
        $cut = $this->totals($mine, TimeEntryGrouping::Subject, 2);
        self::assertSame([2, true], [count($cut->totals), $cut->truncated]);
        self::assertFalse($this->totals($mine, TimeEntryGrouping::Subject, 4)->truncated);

        // Exactly one SQL statement per call, none when nothing can match.
        foreach (TimeEntryGrouping::cases() as $grouping) {
            self::assertSame(1, $this->statements(fn (): TimeEntryTotals => $this->totals($mine, $grouping)), $grouping->name);
        }
        $nothing = null;
        self::assertSame(0, $this->statements(function () use (&$nothing, $person): void {
            $nothing = $this->totals($this->criteria(ownerIds: [$person], subjects: []), TimeEntryGrouping::Subject);
        }));
        self::assertEquals(new TimeEntryTotals([]), $nothing);
    }

    /**
     * Audit ZBQ-L2: with key filters (owners, scopes, boards, tasks) the totals group the key columns too, check them
     * again in PHP, then merge the groups, sort them (seconds descending, then the key) and cut them in PHP: one group
     * per grouping value whatever the rows behind it, `truncated` counted on the merged groups. Still one statement.
     */
    public function testTotalsMergeTheRowsOfEveryKeyColumnIntoOneGroup(): void
    {
        $scopes                                       = $this->service(FixedScopeProvider::class);
        $scopes->memberships[$this->id($this->other)] = ['org-a', 'org-b'];
        $person                                       = $this->id($this->user);
        $other                                        = $this->id($this->other);
        $this->manual($this->user, TimeAllocation::task('task-1', $this->customer('c-1'), 'dev'), '-2 days 08:00', 600);
        $this->manual($this->user, TimeAllocation::task('task-3', $this->customer('c-1'), 'dev'), '-2 days 09:00', 1200);
        $this->manual($this->other, TimeAllocation::task('task-1', $this->customer('c-1'), 'dev'), '-2 days 08:00', 300);
        $this->manual($this->other, TimeAllocation::task('task-3', $this->customer('c-2'), 'support'), '-2 days 10:00', 2100);
        $this->manual($this->user, TimeAllocation::task('task-1', null, 'meeting'), '-2 days 11:00', 2100);
        $this->manual($this->user, TimeAllocation::task('task-3'), '-2 days 12:00', 600);
        $keys = fn (string ...$taskIds): TimeEntryCriteria => new TimeEntryCriteria(ownerIds: [$person, $other], scopeKeys: ['org-a', 'org-b'], taskIds: $taskIds === [] ? ['task-1', 'task-3'] : array_values($taskIds), boardIds: ['board-1', 'board-2'], startedFrom: $this->at('-3 days 00:00'), startedTo: $this->at('+1 day 00:00'));

        // c-1 sits on three rows (two owners, two scopes, two boards, two tasks); the subject-less time on two.
        self::assertSame([['|', 2700, 2], ['customer|c-1', 2100, 3], ['customer|c-2', 2100, 1]], $this->grouped($keys(), TimeEntryGrouping::Subject), 'ties by the key: c-1 before c-2');
        self::assertSame([['dev', 2100, 3], ['meeting', 2100, 1], ['support', 2100, 1], ['', 600, 1]], $this->grouped($keys(), TimeEntryGrouping::ActivityType));
        self::assertSame([[$person, 4500, 4], [$other, 2400, 2]], $this->grouped($keys(), TimeEntryGrouping::Owner));
        self::assertSame([['|', 2100, 1], ['customer|c-1', 900, 2]], $this->grouped($keys('task-1'), TimeEntryGrouping::Subject), 'one task: its rows only');

        // The cut counts merged groups: three groups from six rows are not truncated at a limit of three.
        $cut = $this->totals($keys(), TimeEntryGrouping::Subject, 2);
        self::assertSame([2, true], [count($cut->totals), $cut->truncated]);
        self::assertFalse($this->totals($keys(), TimeEntryGrouping::Subject, 3)->truncated);
        self::assertSame(1, $this->statements(fn (): TimeEntryTotals => $this->totals($keys(), TimeEntryGrouping::Subject)));

        // Several scopes alone (the scope column only): the person's time in org-a and org-b is one group per subject.
        self::assertSame([['|', 2700, 2], ['customer|c-1', 1800, 2]], $this->grouped(new TimeEntryCriteria(ownerIds: [$person], scopeKeys: ['org-a', 'org-b'], startedFrom: $this->at('-3 days 00:00'), startedTo: $this->at('+1 day 00:00')), TimeEntryGrouping::Subject));
    }

    public function testTotalsRefuseUnboundedQueries(): void
    {
        $from  = new DateTimeImmutable('2026-01-01 00:00:00');
        $owner = [$this->id($this->user)];
        foreach ([
            'open start'         => new TimeEntryCriteria(ownerIds: $owner, startedTo: $from),
            'open end'           => new TimeEntryCriteria(ownerIds: $owner, startedFrom: $from),
            'more than 366 days' => new TimeEntryCriteria(ownerIds: $owner, startedFrom: $from, startedTo: $from->modify('+366 days +1 second')),
            'reversed'           => new TimeEntryCriteria(ownerIds: $owner, startedFrom: $from, startedTo: $from->modify('-1 second')),
        ] as $case => $criteria) {
            try {
                $this->totals($criteria, TimeEntryGrouping::Owner);
                self::fail('expected a refusal: ' . $case);
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('TimeTrack entry totals', $exception->getMessage(), $case);
            }
        }
        $year = new TimeEntryCriteria(ownerIds: $owner, startedFrom: $from, startedTo: $from->modify('+366 days'));
        self::assertSame([], $this->totals($year, TimeEntryGrouping::Owner)->totals, 'exactly 366 days is allowed');
        foreach ([0, TimeEntryTotalsQueryInterface::MAX_GROUPS + 1] as $limit) {
            try {
                $this->totals($year, TimeEntryGrouping::Owner, $limit);
                self::fail('expected a refusal for limit ' . $limit);
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('A TimeTrack query limit', $exception->getMessage());
            }
        }
        self::assertSame([], $this->totals($year, TimeEntryGrouping::Owner, TimeEntryTotalsQueryInterface::MAX_GROUPS)->totals);
    }

    public function testTheGdprExportShowsTheAllocationAndErasureKeepsIt(): void
    {
        $ids                                                  = $this->fixtures();
        $person                                               = $this->id($this->user);
        $this->customers->readableBy[$person]                 = ['c-1'];
        $this->customers->readableBy[$this->id($this->other)] = ['c-2'];
        $this->service(TimerService::class)->start($this->user, TimeAllocation::subject($this->customer('c-1'), 'dev'), ClientType::Web, scopeKey: 'org-b');

        $export = $this->service(TimeTrackPersonalDataInterface::class)->export($person);
        $rows   = [];
        foreach ($export['entries'] as $row) {
            $rows[$row['id']] = $row;
        }
        self::assertSame(['subjectType' => 'customer', 'subjectId' => 'c-1', 'subjectLabel' => 'customer c-1', 'activityTypeKey' => 'dev', 'activityTypeLabel' => 'Development'], $this->allocationOf($rows[$ids['e1']]));
        self::assertSame(['subjectType' => 'customer', 'subjectId' => 'c-2', 'subjectLabel' => 'Subject not visible', 'activityTypeKey' => null, 'activityTypeLabel' => null], $this->allocationOf($rows[$ids['e5']]), 'ids always, the label masked for the data subject');
        self::assertSame(['subjectType' => null, 'subjectId' => null, 'subjectLabel' => null, 'activityTypeKey' => 'support', 'activityTypeLabel' => 'Support'], $this->allocationOf($rows[$ids['e2']]));
        self::assertArrayNotHasKey('subjectType', $rows[$ids['e4']], 'nothing for a row with neither');
        self::assertSame(['subjectType' => 'customer', 'subjectId' => 'c-1', 'subjectLabel' => 'customer c-1', 'activityTypeKey' => 'dev', 'activityTypeLabel' => 'Development'], $this->allocationOf($export['runningTimer'] ?? []));
        $actors = array_map(static fn (array $call): ?string => $call['actor'], $this->customers->describeCalls);
        self::assertSame([$person], array_values(array_unique($actors)), 'labels resolved with the data subject as actor');

        // Free text erasure and anonymisation keep the three columns and the time.
        $this->service(TimerService::class)->stop($this->user);
        $this->service(TimeTrackPersonalDataInterface::class)->erase($this->id($this->other), TimeEntryErasure::FreeText);
        $this->service(TimeTrackPersonalDataInterface::class)->erase($person);
        $rows = $this->entityManager()->getConnection()->fetchAllAssociative('SELECT id, user_id, subject_type, subject_id, activity_type_key, duration_seconds FROM time_track_entries WHERE id IN (?, ?, ?)', [$ids['e1'], $ids['e5'], $ids['e6']]);
        $kept = [];
        foreach ($rows as $row) {
            $kept[$row['id']] = [$row['user_id'] === null, $row['subject_type'], $row['subject_id'], $row['activity_type_key'], (int) $row['duration_seconds']];
        }
        self::assertSame([true, 'customer', 'c-1', 'dev', 3600], $kept[$ids['e1']]);
        self::assertSame([true, 'customer', 'c-2', null, 900], $kept[$ids['e5']]);
        self::assertSame([false, 'customer', 'c-1', 'dev', 2400], $kept[$ids['e6']]);
        self::assertSame('c-1', $this->service(TimeTrackPersonalDataInterface::class)->export($this->id($this->other))['entries'][0]['subjectId'] ?? null);
    }

    /**
     * Six entries: the person's five allocations (org-a and org-b) and the other person's subject entry.
     *
     * @return array<string, string>
     */
    private function fixtures(): array
    {
        return [
            'e1' => $this->manual($this->user, TimeAllocation::task('task-1', $this->customer('c-1'), 'dev'), '-2 days 08:00', 3600),
            'e2' => $this->manual($this->user, TimeAllocation::task('task-1', null, 'support'), '-2 days 10:00', 1800),
            'e3' => $this->manual($this->user, TimeAllocation::subject($this->internal('i-1'), 'meeting'), '-2 days 11:00', 1200, 'org-a'),
            'e4' => $this->manual($this->user, TimeAllocation::none(), '-2 days 12:00', 600, 'org-a'),
            'e5' => $this->manual($this->user, TimeAllocation::subject($this->customer('c-2')), '-2 days 13:00', 900, 'org-b'),
            'e6' => $this->manual($this->other, TimeAllocation::subject($this->customer('c-1'), 'dev'), '-2 days 08:00', 2400, 'org-a'),
        ];
    }

    /**
     * Creates a manual entry and answers its id.
     */
    private function manual(AppUser $user, TimeAllocation $allocation, string $start, int $seconds, ?string $scopeKey = null): string
    {
        $from = $this->at($start);

        return $this->service(ManualEntryService::class)->create($user, $allocation, $from, $from->modify('+' . $seconds . ' seconds'), TimeEntrySource::Manual, scopeKey: $scopeKey)->getId();
    }

    /**
     * Criteria over the last three days.
     *
     * @param list<string>|null $ownerIds
     * @param list<string>|null $scopeKeys
     * @param list<TimeSubjectRef>|null $subjects
     * @param list<string>|null $subjectTypes
     * @param list<string>|null $activityTypeKeys
     */
    private function criteria(?array $ownerIds = null, ?array $scopeKeys = null, ?array $subjects = null, ?array $subjectTypes = null, ?array $activityTypeKeys = null): TimeEntryCriteria
    {
        return new TimeEntryCriteria(ownerIds: $ownerIds, scopeKeys: $scopeKeys, startedFrom: $this->at('-3 days 00:00'), startedTo: $this->at('+1 day 00:00'), subjects: $subjects, subjectTypes: $subjectTypes, activityTypeKeys: $activityTypeKeys);
    }

    /**
     * The groups as `[key, seconds, entries]` in answer order (a subject key is `type|id`, a missing part empty).
     *
     * @return list<array{string, int, int}>
     */
    private function grouped(TimeEntryCriteria $criteria, TimeEntryGrouping $grouping): array
    {
        return array_map(static function (TimeEntryTotal $total) use ($grouping): array {
            $key   = $total->key;
            $label = match ($grouping) {
                TimeEntryGrouping::Subject                => ($key->subject->type ?? '') . '|' . ($key->subject->id ?? ''),
                TimeEntryGrouping::SubjectType            => $key->subjectType ?? '',
                TimeEntryGrouping::ActivityType           => $key->activityTypeKey ?? '',
                TimeEntryGrouping::SubjectAndActivityType => ($key->subject->type ?? '') . '|' . ($key->subject->id ?? '') . '|' . ($key->activityTypeKey ?? ''),
                TimeEntryGrouping::Owner                  => $key->ownerId ?? '',
                TimeEntryGrouping::Task                   => $key->taskId ?? '',
                TimeEntryGrouping::Allocation             => $key->allocation->value ?? '',
            };

            return [$label, $total->seconds, $total->entries];
        }, $this->totals($criteria, $grouping)->totals);
    }

    /**
     * The totals port.
     */
    private function totals(TimeEntryCriteria $criteria, TimeEntryGrouping $grouping, int $limit = 200): TimeEntryTotals
    {
        return $this->service(TimeEntryTotalsQueryInterface::class)->totals($criteria, $grouping, $limit);
    }

    /**
     * Number of SQL statements the call sent (the kernel logs every DBAL statement in debug mode).
     *
     * @param callable(): mixed $call
     */
    private function statements(callable $call): int
    {
        $logger = $this->service('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $before = count($logger->records);
        $call();

        return count(array_filter(array_slice($logger->records, $before), static fn (array $record): bool => str_starts_with($record['message'], 'Executing ')));
    }

    /**
     * The ids the query port lists, sorted.
     *
     * @return list<string>
     */
    private function listed(TimeEntryCriteria $criteria): array
    {
        return $this->sorted(array_map(static fn (TimeEntryRecord $record): string => $record->entry->id, $this->query()->list($criteria)));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * The allocation keys of an exported row.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function allocationOf(array $row): array
    {
        $keys = ['subjectType', 'subjectId', 'subjectLabel', 'activityTypeKey', 'activityTypeLabel'];
        $out  = [];
        foreach ($keys as $key) {
            $out[$key] = $row[$key] ?? null;
        }

        return $out;
    }

    /**
     * The version of the stored entry.
     */
    private function version(string $id): string
    {
        return $this->query()->find($id)?->version() ?? self::fail('entry ' . $id . ' is readable');
    }

    /**
     * A time relative to today.
     */
    private function at(string $relative): DateTimeImmutable
    {
        return new DateTimeImmutable('today ' . $relative);
    }

    /**
     * The user id the ports take.
     */
    private function id(AppUser $user): string
    {
        return (string) $user->getId();
    }

    /**
     * The query port.
     */
    private function query(): TimeEntryQueryInterface
    {
        return $this->service(TimeEntryQueryInterface::class);
    }

    /**
     * The operations port.
     */
    private function operations(): TimeEntryOperationsInterface
    {
        return $this->service(TimeEntryOperationsInterface::class);
    }

    /**
     * A service of the test container.
     *
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertIsObject($service);

        return $service;
    }

    /**
     * A customer subject.
     */
    private function customer(string $id): TimeSubjectRef
    {
        return new TimeSubjectRef('customer', $id);
    }

    /**
     * An internal subject.
     */
    private function internal(string $id): TimeSubjectRef
    {
        return new TimeSubjectRef('internal', $id);
    }
}
