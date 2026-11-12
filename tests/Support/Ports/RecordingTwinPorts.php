<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarCredential;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialSourceInterface;
use Nowo\TimeTrackBundle\Calendar\TaskKeyLookupInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettings;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsPolicyInterface;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingPage;
use Nowo\TimeTrackBundle\Meeting\MeetingQuery;
use Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncState;
use Nowo\TimeTrackBundle\Meeting\MeetingSyncStatus;
use Nowo\TimeTrackBundle\Repository\EntryOwnerRefsRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\CalendarConnectionRef;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDay;
use Nowo\TimeTrackBundle\WorkingTime\CalendarDayKind;
use Nowo\TimeTrackBundle\WorkingTime\WorkedDay;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeRecordInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarSourceInterface;
use stdClass;

use function array_key_last;
use function assert;

/**
 * Implements every 1.23.0 port twin (REQ-ARCH-001 rule 3), records each call (method and arguments) and answers with
 * fixed values, so a test can check what a gate passes on and returns.
 */
final class RecordingTwinPorts implements IdleCheckSettingsPolicyInterface, ClientCredentialMinterInterface, ClientUserProfileSourceInterface, ClientUserDaysOffSourceInterface, ClientUserLocaleSourceInterface, ClientUserPrivacySourceInterface, TeamDirectoryInterface, BatchTaskDirectoryInterface, UserReferenceProviderInterface, TaskAccessPolicyInterface, TimesheetApproverPolicyInterface, TimesheetScopePolicyInterface, WorkdaySourceInterface, WorkingCalendarSourceInterface, WorkedTimeRecordInterface, MeetingSourceInterface, TaskKeyLookupInterface, CalendarCredentialSourceInterface, EntryOwnerRefsRepositoryInterface
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];

    /** @var list<array{user: TimeTrackUserRef, scopeKey: string}> */
    public array $owners = [];

    public object $reference;

    public function __construct()
    {
        $this->reference = new stdClass();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function last(): array
    {
        $last = $this->calls[array_key_last($this->calls) ?? 0] ?? null;
        assert($last !== null);

        return $last;
    }

    public function settingsFor(TimeTrackUserRef $user): IdleCheckSettings
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return new IdleCheckSettings(false, 12, 4, true);
    }

    public function mintForDevice(TimeTrackUserRef $user, ClientType $clientType, string $clientName, array $scopes): string
    {
        $this->calls[] = [__FUNCTION__, [$user, $clientType, $clientName, $scopes]];

        return 'credential-1';
    }

    public function displayNameOf(TimeTrackUserRef $user): string
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return 'Name ' . $user->identifier;
    }

    public function workingHoursOf(TimeTrackUserRef $user): array
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return ['timezone' => 'UTC', 'slots' => []];
    }

    public function daysOffOf(TimeTrackUserRef $user, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = [__FUNCTION__, [$user, $from, $to]];

        return [['date' => '2026-12-25', 'kind' => 'holiday', 'label' => 'Christmas']];
    }

    public function localeOf(TimeTrackUserRef $user): string
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return 'es';
    }

    public function privacyOf(TimeTrackUserRef $user, ?string $locale): array
    {
        $this->calls[] = [__FUNCTION__, [$user, $locale]];

        return ['url' => 'https://example.com/privacy'];
    }

    public function teamIdsOf(TimeTrackUserRef $user): array
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return ['team-1'];
    }

    public function manages(TimeTrackUserRef $manager, TimeTrackUserRef $member): bool
    {
        $this->calls[] = [__FUNCTION__, [$manager, $member]];

        return true;
    }

    public function managedUserIdsOf(TimeTrackUserRef $manager): array
    {
        $this->calls[] = [__FUNCTION__, [$manager]];

        return ['u-2'];
    }

    public function findTaskFor(string $taskId, TimeTrackUserRef $user): TaskReference
    {
        $this->calls[] = [__FUNCTION__, [$taskId, $user]];

        return new TaskReference($taskId, 'Task ' . $taskId);
    }

    public function listTrackableFor(TimeTrackUserRef $user, TaskListQuery $query): array
    {
        $this->calls[] = [__FUNCTION__, [$user, $query]];

        return [new TaskReference('task-1', 'Task task-1')];
    }

    public function canTrackTask(TimeTrackUserRef $user, string $taskId): bool
    {
        $this->calls[] = [__FUNCTION__, [$user, $taskId]];

        return true;
    }

    public function filterTrackableFor(TimeTrackUserRef $user, array $taskIds): array
    {
        $this->calls[] = [__FUNCTION__, [$user, $taskIds]];

        return [$taskIds[0]];
    }

    public function referenceFor(TimeTrackUserRef $user): object
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return $this->reference;
    }

    public function mayTrack(TimeTrackUserRef $user, string $taskId): bool
    {
        $this->calls[] = [__FUNCTION__, [$user, $taskId]];

        return true;
    }

    public function mayApprove(TimeTrackUserRef $approver, string $ownerId, string $scopeKey): bool
    {
        $this->calls[] = [__FUNCTION__, [$approver, $ownerId, $scopeKey]];

        return true;
    }

    public function reviewableUserIdsFor(TimeTrackUserRef $approver, string $scopeKey): array
    {
        $this->calls[] = [__FUNCTION__, [$approver, $scopeKey]];

        return ['u-3'];
    }

    public function approverIdsFor(string $ownerId, string $scopeKey): array
    {
        $this->calls[] = [__FUNCTION__, [$ownerId, $scopeKey]];

        return ['u-4'];
    }

    public function scopesOf(TimeTrackUserRef $user): array
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return [new TimesheetScope('org-a', 'Org A')];
    }

    public function scopeKeyForUserTask(TimeTrackUserRef $user, string $taskId, ?string $boardId): string
    {
        $this->calls[] = [__FUNCTION__, [$user, $taskId, $boardId]];

        return 'org-a';
    }

    public function scopeKeysForEntryViews(array $entries): array
    {
        $this->calls[] = [__FUNCTION__, [$entries]];
        $keys          = [];
        foreach ($entries as $entry) {
            $keys[$entry->id] = $entry->boardId;
        }

        return $keys;
    }

    public function todayFor(TimeTrackUserRef $user): WorkdayState
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return new WorkdayState('off', '2026-11-12', 'UTC');
    }

    public function actFor(TimeTrackUserRef $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState
    {
        $this->calls[] = [__FUNCTION__, [$user, $action, $occurredAt, $idempotencyKey, $client]];

        return new WorkdayState('working', '2026-11-12', 'UTC');
    }

    public function daysFor(TimeTrackUserRef $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = [__FUNCTION__, [$user, $scopeKey, $from, $to]];

        return [new CalendarDay('2026-11-12', 'UTC', 28800, CalendarDayKind::Working)];
    }

    public function isLegalRecord(): bool
    {
        $this->calls[] = [__FUNCTION__, []];

        return true;
    }

    public function workedDaysOf(TimeTrackUserRef $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = [__FUNCTION__, [$user, $scopeKey, $from, $to]];

        return ['2026-11-12' => new WorkedDay('2026-11-12', 3600)];
    }

    public function meetingsOf(TimeTrackUserRef $user, MeetingQuery $query): MeetingPage
    {
        $this->calls[] = [__FUNCTION__, [$user, $query]];

        return new MeetingPage([], new MeetingSyncState(MeetingSyncStatus::Connected));
    }

    public function syncStateOf(TimeTrackUserRef $user): MeetingSyncState
    {
        $this->calls[] = [__FUNCTION__, [$user]];

        return new MeetingSyncState(MeetingSyncStatus::NotConnected);
    }

    public function extractKeys(string $title): array
    {
        $this->calls[] = [__FUNCTION__, [$title]];

        return ['#abc'];
    }

    public function resolveFor(TimeTrackUserRef $user, string $scopeKey, array $keys): array
    {
        $this->calls[] = [__FUNCTION__, [$user, $scopeKey, $keys]];

        return ['#abc' => new TaskReference('task-1', 'Task task-1')];
    }

    public function credentialOf(TimeTrackUserRef $user, CalendarConnectionRef $connection): CalendarCredential
    {
        $this->calls[] = [__FUNCTION__, [$user, $connection]];

        return new CalendarCredential('fake-token');
    }

    public function revokeFor(TimeTrackUserRef $user, CalendarConnectionRef $connection): void
    {
        $this->calls[] = [__FUNCTION__, [$user, $connection]];
    }

    public function connectUrlFor(TimeTrackUserRef $user, string $provider, string $scopeKey): string
    {
        $this->calls[] = [__FUNCTION__, [$user, $provider, $scopeKey]];

        return 'https://example.com/connect/' . $provider;
    }

    public function ownerRefsWithEntries(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->calls[] = [__FUNCTION__, [$from, $to]];

        return $this->owners;
    }
}
