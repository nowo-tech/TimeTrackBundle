<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Port;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TimeEntryView;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Port\BatchTaskDirectoryGate;
use Nowo\TimeTrackBundle\Port\CalendarCredentialSourceGate;
use Nowo\TimeTrackBundle\Port\ClientCredentialMinterGate;
use Nowo\TimeTrackBundle\Port\ClientUserDaysOffSourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserLocaleSourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserPrivacySourceGate;
use Nowo\TimeTrackBundle\Port\ClientUserProfileSourceGate;
use Nowo\TimeTrackBundle\Port\EntryOwnerRefsGate;
use Nowo\TimeTrackBundle\Port\IdleCheckSettingsPolicyGate;
use Nowo\TimeTrackBundle\Port\MeetingSourceGate;
use Nowo\TimeTrackBundle\Port\TaskAccessPolicyGate;
use Nowo\TimeTrackBundle\Port\TaskDirectoryGate;
use Nowo\TimeTrackBundle\Port\TaskKeyLookupGate;
use Nowo\TimeTrackBundle\Port\TeamDirectoryGate;
use Nowo\TimeTrackBundle\Port\TimesheetApproverPolicyGate;
use Nowo\TimeTrackBundle\Port\TimesheetScopePolicyGate;
use Nowo\TimeTrackBundle\Port\UserReferenceProviderGate;
use Nowo\TimeTrackBundle\Port\WorkdaySourceGate;
use Nowo\TimeTrackBundle\Port\WorkedTimeRecordGate;
use Nowo\TimeTrackBundle\Port\WorkingCalendarSourceGate;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\Ports\RecordingTwinPorts;
use Nowo\TimeTrackBundle\ValueObject\CalendarConnectionRef;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use PHPUnit\Framework\TestCase;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): every gate asks the twin with the reference of the user it was called with (class, id,
 * identifier, roles and the same object), passes the other arguments unchanged and returns the twin's answer as is.
 */
final class GatesTest extends TestCase
{
    private RecordingTwinPorts $twin;

    private TestUser $user;

    protected function setUp(): void
    {
        $this->twin = new RecordingTwinPorts();
        $this->user = new TestUser('42', 'ana@example.com', ['ROLE_USER', 'ROLE_MANAGER']);
    }

    public function testClientGates(): void
    {
        $idle = (new IdleCheckSettingsPolicyGate($this->twin))->forUser($this->user);
        self::assertNotNull($idle);
        self::assertFalse($idle->enabled);
        $this->assertCall('settingsFor', 0);

        self::assertSame('credential-1', (new ClientCredentialMinterGate($this->twin))->issueForDevice($this->user, ClientType::Desktop, 'Laptop', [ClientScope::TimerRead]));
        [, $args] = $this->assertCall('mintForDevice', 0);
        self::assertSame([ClientType::Desktop, 'Laptop', [ClientScope::TimerRead]], [$args[1], $args[2], $args[3]]);

        $profile = new ClientUserProfileSourceGate($this->twin);
        self::assertSame($this->twin, $profile->source());
        self::assertSame('Name ana@example.com', $profile->getDisplayName($this->user));
        $this->assertCall('displayNameOf', 0);
        self::assertSame(['timezone' => 'UTC', 'slots' => []], $profile->getWorkingHours($this->user));
        $this->assertCall('workingHoursOf', 0);

        $from = new DateTimeImmutable('2026-11-12 00:00:00');
        $to   = new DateTimeImmutable('2027-01-11 23:59:59');
        self::assertSame([['date' => '2026-12-25', 'kind' => 'holiday', 'label' => 'Christmas']], (new ClientUserDaysOffSourceGate($this->twin))->getDaysOff($this->user, $from, $to));
        [, $args] = $this->assertCall('daysOffOf', 0);
        self::assertSame([$from, $to], [$args[1], $args[2]]);

        self::assertSame('es', (new ClientUserLocaleSourceGate($this->twin))->getLocale($this->user));
        $this->assertCall('localeOf', 0);

        self::assertSame(['url' => 'https://example.com/privacy'], (new ClientUserPrivacySourceGate($this->twin))->getPrivacy($this->user, 'pt_BR'));
        [, $args] = $this->assertCall('privacyOf', 0);
        self::assertSame('pt_BR', $args[1]);
    }

    public function testIntegrationGates(): void
    {
        $team   = new TeamDirectoryGate($this->twin);
        $member = new TestUser('7', 'bob@example.com');
        self::assertSame(['team-1'], $team->getTeamIdsForUser($this->user));
        $this->assertCall('teamIdsOf', 0);
        self::assertTrue($team->isManagerOf($this->user, $member));
        [, $args] = $this->assertCall('manages', 0);
        self::assertInstanceOf(TimeTrackUserRef::class, $args[1]);
        self::assertSame(['7', 'bob@example.com', $member], [$args[1]->id, $args[1]->identifier, $args[1]->user()]);
        self::assertSame(['u-2'], $team->getManagedUserIds($this->user));
        $this->assertCall('managedUserIdsOf', 0);

        $query = new TaskListQuery('fix', 10, 0);
        foreach ([new TaskDirectoryGate($this->twin), new BatchTaskDirectoryGate($this->twin)] as $tasks) {
            self::assertSame('task-9', $tasks->findForUser('task-9', $this->user)?->id);
            [, $args] = $this->assertCall('findTaskFor', 1);
            self::assertSame('task-9', $args[0]);
            self::assertSame('task-1', $tasks->listTrackableForUser($this->user, $query)[0]->id);
            [, $args] = $this->assertCall('listTrackableFor', 0);
            self::assertSame($query, $args[1]);
            self::assertTrue($tasks->canUserTrack($this->user, 'task-9'));
            [, $args] = $this->assertCall('canTrackTask', 0);
            self::assertSame('task-9', $args[1]);
        }
        self::assertSame(['task-2'], (new BatchTaskDirectoryGate($this->twin))->filterTrackable($this->user, ['task-2', 'task-3']));
        [, $args] = $this->assertCall('filterTrackableFor', 0);
        self::assertSame(['task-2', 'task-3'], $args[1]);

        self::assertSame($this->twin->reference, (new UserReferenceProviderGate($this->twin))->reference($this->user));
        $this->assertCall('referenceFor', 0);

        self::assertTrue((new TaskAccessPolicyGate($this->twin))->canTrack($this->user, 'task-5'));
        [, $args] = $this->assertCall('mayTrack', 0);
        self::assertSame('task-5', $args[1]);
    }

    public function testTimesheetGates(): void
    {
        $approver = new TimesheetApproverPolicyGate($this->twin);
        self::assertTrue($approver->canApprove($this->user, 'u-9', 'org-a'));
        [, $args] = $this->assertCall('mayApprove', 0);
        self::assertSame(['u-9', 'org-a'], [$args[1], $args[2]]);
        self::assertSame(['u-3'], $approver->reviewableUserIds($this->user, 'org-a'));
        $this->assertCall('reviewableUserIdsFor', 0);
        self::assertSame(['u-4'], $approver->approverIdsFor('u-9', 'org-a'));
        self::assertSame(['approverIdsFor', ['u-9', 'org-a']], $this->twin->last());

        $scopes = new TimesheetScopePolicyGate($this->twin);
        self::assertSame('org-a', $scopes->scopesFor($this->user)[0]->key);
        $this->assertCall('scopesOf', 0);
        self::assertSame('org-a', $scopes->scopeKeyForTask($this->user, 'task-1', 'board-1'));
        [, $args] = $this->assertCall('scopeKeyForUserTask', 0);
        self::assertSame(['task-1', 'board-1'], [$args[1], $args[2]]);
        $entry = new TimeEntry($this->user, 'task-1', 'Task', 'board-7', new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Desktop);
        self::assertSame([$entry->getId() => 'board-7'], $scopes->scopeKeysForEntries([$entry]));
        [, $args] = $this->twin->last();
        self::assertIsArray($args[0]);
        self::assertInstanceOf(TimeEntryView::class, $args[0][0]);
        self::assertSame([$entry->getId(), '42'], [$args[0][0]->id, $args[0][0]->userId]);
    }

    public function testWorkingTimeAndWorkdayGates(): void
    {
        $at      = new DateTimeImmutable('2026-11-12 08:00:00');
        $workday = new WorkdaySourceGate($this->twin);
        self::assertSame('off', $workday->today($this->user)?->status);
        $this->assertCall('todayFor', 0);
        self::assertSame('working', $workday->act($this->user, WorkdayAction::ClockIn, $at, 'key-1', ClientType::Desktop)->status);
        [, $args] = $this->assertCall('actFor', 0);
        self::assertSame([WorkdayAction::ClockIn, $at, 'key-1', ClientType::Desktop], [$args[1], $args[2], $args[3], $args[4]]);

        $from = new DateTimeImmutable('2026-11-01');
        $to   = new DateTimeImmutable('2026-11-30');
        self::assertSame(28800, (new WorkingCalendarSourceGate($this->twin))->days($this->user, 'org-a', $from, $to)[0]->expectedSeconds ?? null);
        [, $args] = $this->assertCall('daysFor', 0);
        self::assertSame(['org-a', $from, $to], [$args[1], $args[2], $args[3]]);

        $worked = new WorkedTimeRecordGate($this->twin);
        self::assertTrue($worked->isLegalRecord());
        self::assertSame(3600, $worked->workedDays($this->user, 'org-a', $from, $to)['2026-11-12']->workedSeconds);
        [, $args] = $this->assertCall('workedDaysOf', 0);
        self::assertSame(['org-a', $from, $to], [$args[1], $args[2], $args[3]]);
    }

    public function testMeetingAndCalendarGates(): void
    {
        $meetings = new MeetingSourceGate($this->twin);
        $query    = new MeetingQuery(new DateTimeImmutable('2026-11-12'), new DateTimeImmutable('2026-11-13'), new DateTimeZone('UTC'), 10);
        self::assertSame([], $meetings->meetingsFor($this->user, $query)->meetings);
        [, $args] = $this->assertCall('meetingsOf', 0);
        self::assertSame($query, $args[1]);
        self::assertSame('not_connected', $meetings->stateFor($this->user)->status->value);
        $this->assertCall('syncStateOf', 0);

        $keys = new TaskKeyLookupGate($this->twin);
        self::assertSame(['#abc'], $keys->extractKeys('Review #abc'));
        self::assertSame(['extractKeys', ['Review #abc']], $this->twin->last());
        self::assertSame('task-1', $keys->resolve($this->user, 'org-a', ['#abc'])['#abc']->id);
        [, $args] = $this->assertCall('resolveFor', 0);
        self::assertSame(['org-a', ['#abc']], [$args[1], $args[2]]);

        $connection  = new CalendarConnection($this->user, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'cred-1', 'v1', new DateTimeImmutable('2026-11-12 08:00:00'));
        $credentials = new CalendarCredentialSourceGate($this->twin);
        self::assertNotNull($credentials->credentialFor($this->user, $connection));
        [, $args] = $this->assertCall('credentialOf', 0);
        self::assertEquals(new CalendarConnectionRef($connection->getId(), '42', 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'cred-1'), $args[1]);
        $credentials->revoke($this->user, $connection);
        [, $args] = $this->assertCall('revokeFor', 0);
        self::assertInstanceOf(CalendarConnectionRef::class, $args[1]);
        self::assertSame($connection->getId(), $args[1]->id);
        self::assertSame('https://example.com/connect/google', $credentials->connectUrl($this->user, 'google', 'org-a'));
        [, $args] = $this->assertCall('connectUrlFor', 0);
        self::assertSame(['google', 'org-a'], [$args[1], $args[2]]);
    }

    public function testEntryOwnerRefsGateReturnsTheSecurityUsersOrRefusesAReferenceWithout(): void
    {
        $from               = new DateTimeImmutable('2026-10-01');
        $to                 = new DateTimeImmutable('2026-11-01');
        $this->twin->owners = [['user' => TimeTrackUserRef::of($this->user, TestUser::class, '42', 'ana@example.com'), 'scopeKey' => 'org-a']];
        self::assertSame([['user' => $this->user, 'scopeKey' => 'org-a']], (new EntryOwnerRefsGate($this->twin))->ownersWithEntries($from, $to));
        self::assertSame(['ownerRefsWithEntries', [$from, $to]], $this->twin->last());

        $this->twin->owners = [['user' => TimeTrackUserRef::ofId(TestUser::class, '42'), 'scopeKey' => 'org-a']];
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ownerRefsWithEntries() returned a user reference without a Symfony security user object');
        (new EntryOwnerRefsGate($this->twin))->ownersWithEntries($from, $to);
    }

    /**
     * Asserts the last call was `$method` and its argument `$userArgument` is the reference of the test user.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function assertCall(string $method, int $userArgument): array
    {
        $last = $this->twin->last();
        self::assertSame($method, $last[0]);
        $ref = $last[1][$userArgument] ?? null;
        self::assertInstanceOf(TimeTrackUserRef::class, $ref);
        self::assertSame([TestUser::class, '42', 'ana@example.com', ['ROLE_USER', 'ROLE_MANAGER']], [$ref->userClass, $ref->id, $ref->identifier, $ref->roles]);
        self::assertSame($this->user, $ref->user());

        return $last;
    }
}
