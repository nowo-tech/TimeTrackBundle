<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackEntityManager;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Entity\MonitoringAcknowledgementRecord;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetAction;
use Nowo\TimeTrackBundle\Enum\TimesheetActorRole;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Exception\PersonalDataErasureRefusedException;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryData;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\PersonalData\TimeEntryErasure;
use Nowo\TimeTrackBundle\PersonalData\TimeTrackPersonalDataInterface;
use Nowo\TimeTrackBundle\Query\ClientAccessQueryInterface;
use Nowo\TimeTrackBundle\Query\RunningTimerQueryInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryCriteria;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Query\TimeEntrySort;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;
use function array_map;
use function bin2hex;
use function gc_collect_cycles;
use function is_object;
use function json_encode;
use function memory_get_usage;
use function method_exists;
use function random_bytes;
use function sprintf;
use function str_repeat;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

/**
 * 1.35.0 (DDD-host checklist B7): the entry, timer and client read ports and the personal-data port, run on a standard
 * host ({@see HostReadPortsStandardTest}) and on an id / XML-mapping host with the private entity manager
 * ({@see HostReadPortsDddHostTest}). The scenarios are the queries Beacon writes in DQL today.
 */
abstract class HostReadPortsTestCase extends FunctionalTestCase
{
    /**
     * A host user of the variant (persisted when the variant needs it).
     *
     * @param non-empty-string $name
     */
    abstract protected function person(string $name): UserInterface;

    /**
     * The user id the ports take for that user.
     */
    abstract protected function idOf(UserInterface $user): string;

    public function testEntriesAreListedCountedAndSummedByOwnerScopeTaskAndPeriod(): void
    {
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        $a1          = $this->entry($ana, 'task-1', '2026-11-02 09:00', 60, metadata: ['note' => 'Standup', 'auto_stopped' => true]);
        $a2          = $this->entry($ana, 'task-2', '2026-11-03 09:00', 30);
        $a3          = $this->imported($ana, 'task-1', '2026-11-04 09:00', 45, 'Imported text');
        $a4          = $this->entry($ana, 'task-1', '2026-11-05 09:00', 15, scope: 'org-b', board: 'board-2');
        $this->entry($bob, 'task-1', '2026-11-02 10:00', 120);
        $query = $this->query();

        $own = $query->list(TimeEntryCriteria::ofOwner($this->idOf($ana)));
        self::assertSame([$a4, $a3, $a2, $a1], $this->ids($own), 'newest first');
        self::assertSame($this->idOf($ana), $own[3]->entry->userId);
        self::assertSame(['Standup', null, true], [$own[3]->note, $own[3]->description, $own[3]->metadata['auto_stopped'] ?? null]);
        self::assertFalse($own[3]->isReadOnly());
        self::assertSame(3600, $own[3]->entry->durationSeconds);
        self::assertSame(['Imported text', true, TimeEntrySource::Import], [$own[1]->description, $own[1]->billable, $own[1]->entry->source]);
        self::assertTrue($own[1]->isReadOnly());

        self::assertSame([$a1, $a2, $a3, $a4], $this->ids($query->list(TimeEntryCriteria::ofOwner($this->idOf($ana)), TimeEntrySort::StartedAtAscending)));
        // By id in the database's collation order (PostgreSQL's may differ from PHP's byte order).
        self::assertEqualsCanonicalizing([$a1, $a2, $a3, $a4], $this->ids($query->list(TimeEntryCriteria::ofOwner($this->idOf($ana)), TimeEntrySort::IdAscending)));
        self::assertSame([$a3, $a2], $this->ids($query->list(TimeEntryCriteria::ofOwner($this->idOf($ana)), limit: 2, offset: 1)), 'offset paging');
        self::assertSame([], $query->list(TimeEntryCriteria::ofOwner($this->idOf($ana)), offset: 10));

        $tenant = new TimeEntryCriteria(scopeKeys: ['org-a']);
        self::assertSame(4, $query->count($tenant), 'a tenant key without owners lists every owner of the tenant');
        self::assertSame((60 + 30 + 45 + 120) * 60, $query->totalSeconds($tenant));
        $tasks = new TimeEntryCriteria(ownerIds: [$this->idOf($ana), $this->idOf($bob)], taskIds: ['task-1'], boardIds: ['board-1']);
        self::assertSame(3, $query->count($tasks));
        $period = new TimeEntryCriteria(ownerIds: [$this->idOf($ana)], startedFrom: new DateTimeImmutable('2026-11-03 00:00 UTC'), startedTo: new DateTimeImmutable('2026-11-04 09:00 UTC'));
        self::assertSame([$a3, $a2], $this->ids($query->list($period)), 'both ends inclusive');
        self::assertSame(0, $query->count(new TimeEntryCriteria(ownerIds: [])));
        self::assertSame(0, $query->totalSeconds(new TimeEntryCriteria(scopeKeys: [])));
        self::assertSame([], $query->list(new TimeEntryCriteria(taskIds: [])));
        self::assertSame([], $query->listAfter(new TimeEntryCriteria(boardIds: [])));
    }

    public function testKeysetPagesReadEveryEntryOnce(): void
    {
        $ana = $this->person('ana');
        $ids = [];
        for ($i = 0; $i < 7; ++$i) {
            $ids[] = $this->entry($ana, 'task-1', '2026-11-02 0' . $i . ':00', 10);
        }
        $criteria = TimeEntryCriteria::ofOwner($this->idOf($ana));
        $read     = [];
        $after    = '';
        do {
            $page = $this->query()->listAfter($criteria, $after, 3);
            foreach ($page as $record) {
                $read[] = $after = $record->entry->id;
            }
        } while ($page !== []);
        self::assertCount(7, $read, 'no entry twice');
        self::assertEqualsCanonicalizing($ids, $read);
        self::assertSame(7, $this->query()->count($criteria));
    }

    public function testOverlapExclusionSourceAndServiceAccountFilters(): void
    {
        $ana      = $this->person('ana');
        $morning  = $this->entry($ana, 'task-1', '2026-11-02 09:00', 60);
        $noon     = $this->entry($ana, 'task-1', '2026-11-02 12:00', 60);
        $imported = $this->imported($ana, 'task-1', '2026-11-02 15:00', 60, null);
        $id       = $this->idOf($ana);

        // The overlap check of a correction: 09:30–12:30 overlaps both, the entry being corrected is left out.
        $overlap = new TimeEntryCriteria(ownerIds: [$id], overlapFrom: new DateTimeImmutable('2026-11-02 09:30 UTC'), overlapTo: new DateTimeImmutable('2026-11-02 12:30 UTC'), excludeEntryIds: [$morning]);
        self::assertSame([$noon], $this->ids($this->query()->list($overlap)));
        $touching = new TimeEntryCriteria(ownerIds: [$id], overlapFrom: new DateTimeImmutable('2026-11-02 10:00 UTC'), overlapTo: new DateTimeImmutable('2026-11-02 12:00 UTC'));
        self::assertSame(0, $this->query()->count($touching), 'touching ends do not overlap');

        self::assertSame([$imported], $this->ids($this->query()->list(new TimeEntryCriteria(ownerIds: [$id], sources: [TimeEntrySource::Import]))));
        self::assertSame(3, $this->query()->count(new TimeEntryCriteria(ownerIds: [$id], serviceAccount: false)));
        self::assertSame(0, $this->query()->count(new TimeEntryCriteria(ownerIds: [$id], serviceAccount: true)));
    }

    public function testFindReadsOneOwnedEntryByExactIdAndNothingEntersAManager(): void
    {
        $ana   = $this->person('ana');
        $entry = $this->entry($ana, 'task-1', '2026-11-02 09:00', 60);
        $this->bundleManager()->clear();

        $found = $this->query()->find($entry);
        self::assertInstanceOf(TimeEntryRecord::class, $found);
        self::assertSame(['task-1', 'Task task-1', 'board-1', 'org-a'], [$found->entry->taskId, $found->entry->taskTitle, $found->entry->boardId, $found->entry->scopeKey]);
        self::assertNull($this->query()->find(strtoupper($entry)));
        self::assertNull($this->query()->find('unknown'));
        self::assertNull($this->query()->find(''));
        self::assertNull($this->query()->find(str_repeat('x', 37)));
        $this->query()->list(TimeEntryCriteria::ofOwner($this->idOf($ana)));
        self::assertSame([], $this->bundleManager()->getUnitOfWork()->getIdentityMap(), 'views only: no entity is hydrated');
    }

    public function testLimitsAndOffsetsAreChecked(): void
    {
        $criteria = TimeEntryCriteria::ofOwner('1');
        foreach ([
            fn (): array => $this->query()->list($criteria, limit: 0),
            fn (): array => $this->query()->list($criteria, limit: TimeEntryQueryInterface::MAX_LIMIT + 1),
            fn (): array => $this->query()->list($criteria, offset: -1),
            fn (): array => $this->query()->listAfter($criteria, '', 0),
            fn (): array => $this->timerQuery()->startedBefore(new DateTimeImmutable(), '', 0),
        ] as $call) {
            try {
                $call();
                self::fail('expected a refusal');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRunningTimersByOwnerAndByAge(): void
    {
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        $timers      = $this->timers();
        $timers->start($ana, 'task-1', ClientType::Desktop, ['note' => 'Focus']);
        $timers->start($bob, 'task-2', ClientType::Web);
        $this->bundleManager()->clear();

        $running = $this->timerQuery()->runningOf($this->idOf($ana));
        self::assertNotNull($running);
        self::assertSame([$this->idOf($ana), 'task-1', ClientType::Desktop, 'Focus'], [$running->ownerId, $running->timer->taskId, $running->timer->clientType, $running->timer->note]);
        self::assertNull($this->timerQuery()->runningOf('nobody'));
        self::assertNull($this->timerQuery()->runningOf(' ' . $this->idOf($ana)));

        $old = $this->timerQuery()->startedBefore(new DateTimeImmutable('+1 minute'));
        self::assertCount(2, $old);
        $first = $this->timerQuery()->startedBefore(new DateTimeImmutable('+1 minute'), '', 1);
        self::assertCount(1, $first);
        self::assertCount(1, $this->timerQuery()->startedBefore(new DateTimeImmutable('+1 minute'), $first[0]->timer->timerId), 'keyset');
        self::assertSame([], $this->timerQuery()->startedBefore(new DateTimeImmutable('-1 hour')));
    }

    public function testClientSessionsAndDeviceSignInsWithoutSecrets(): void
    {
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        $valid       = new ClientToken('hash-valid', new DateTimeImmutable('+1 day'), $this->owner($ana), ClientType::Desktop, new DateTimeImmutable('-1 hour'), 'app-1', [ClientScope::TimerWrite]);
        $this->persist($valid);
        $this->persist(new ClientToken('hash-old', new DateTimeImmutable('-1 day'), $this->owner($ana), ClientType::Extension, new DateTimeImmutable('-2 days')));
        $this->persist(new ClientToken('hash-bob', new DateTimeImmutable('+1 day'), $this->owner($bob), ClientType::Desktop));
        $device = new DeviceAuthorization('device-hash', 'user-hash', ClientType::Desktop, 'Ana laptop', [ClientScope::TimerWrite], new DateTimeImmutable('+10 minutes'), new DateTimeImmutable('-1 minute'), '192.0.2.0/24');
        $device->approve($this->owner($ana), 'app-1');
        $this->persist($device);

        $sessions = $this->clientAccess()->sessionsOf($this->idOf($ana));
        self::assertCount(1, $sessions);
        self::assertSame([$valid->getId(), ClientType::Desktop, 'app-1', [ClientScope::TimerWrite->value]], [$sessions[0]->id, $sessions[0]->clientType, $sessions[0]->credentialId, $sessions[0]->scopes]);
        $all = $this->clientAccess()->sessionsOf($this->idOf($ana), true);
        self::assertCount(2, $all);
        self::assertSame(ClientScope::values(ClientScope::defaults()), $all[1]->scopes, 'a pre-1.9 session has the default scopes');
        self::assertSame([], $this->clientAccess()->sessionsOf($this->idOf($ana) . ' '));
        self::assertStringNotContainsString('hash-valid', json_encode($all, JSON_THROW_ON_ERROR));

        $devices = $this->clientAccess()->deviceSignInsOf($this->idOf($ana));
        self::assertCount(1, $devices);
        self::assertSame(['Ana laptop', DeviceAuthorizationStatus::Approved, '192.0.2.0/24'], [$devices[0]->clientName, $devices[0]->status, $devices[0]->ipPrefix]);
        self::assertSame([], $this->clientAccess()->deviceSignInsOf($this->idOf($bob)));
        self::assertSame([], $this->clientAccess()->deviceSignInsOf(''));

        $revoker = self::getContainer()->get(ClientTokenRevoker::class);
        self::assertInstanceOf(ClientTokenRevoker::class, $revoker);
        self::assertSame(2, $revoker->revokeAllForUser($this->idOf($ana)));
        self::assertSame(0, $revoker->revokeAllForUser("\u{200B}"));
        self::assertSame([], $this->clientAccess()->sessionsOf($this->idOf($ana), true));
        self::assertCount(1, $this->clientAccess()->sessionsOf($this->idOf($bob)));
    }

    public function testTheExportHoldsEverySectionAndNoSecret(): void
    {
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        $this->entry($ana, 'task-1', '2026-11-02 09:00', 60, metadata: ['note' => 'Standup']);
        $this->entry($bob, 'task-1', '2026-11-02 09:00', 60);
        $this->seedPersonalData($ana);
        $this->timers()->start($ana, 'task-2', ClientType::Web);

        $export = $this->personalData()->export($this->idOf($ana));
        self::assertSame(['entries', 'formerPersonEntries', 'runningTimer', 'clientSessions', 'deviceSignIns', 'monitoringAcknowledgements', 'monitoringUploads', 'timesheets', 'balanceLedgerLines', 'timesheetEvents', 'calendarConnections', 'calendarRules', 'calendarSuggestions'], array_keys($export));
        self::assertIsArray($export['entries']);
        self::assertCount(1, $export['entries']);
        self::assertSame(['Standup', 3600], [$export['entries'][0]['note'], $export['entries'][0]['durationSeconds']]);
        self::assertIsString($export['entries'][0]['startedAt']);
        self::assertStringStartsWith('2026-11-02T', $export['entries'][0]['startedAt']);
        self::assertIsArray($export['runningTimer']);
        self::assertSame('task-2', $export['runningTimer']['taskId']);
        self::assertIsArray($export['clientSessions']);
        self::assertCount(1, $export['clientSessions']);
        self::assertIsArray($export['deviceSignIns']);
        self::assertSame('approved', $export['deviceSignIns'][0]['status']);
        self::assertIsArray($export['monitoringAcknowledgements']);
        self::assertSame(3, $export['monitoringAcknowledgements'][0]['policyVersion']);
        self::assertIsArray($export['monitoringUploads']);
        self::assertSame(['pending', 'image/webp'], [$export['monitoringUploads'][0]['status'], $export['monitoringUploads'][0]['mime']]);
        self::assertIsArray($export['timesheets']);
        self::assertSame(['week', 'open', 'manual'], [$export['timesheets'][0]['periodKind'], $export['timesheets'][0]['status'], $export['timesheets'][0]['lockReason']]);
        self::assertIsString($export['timesheets'][0]['lockedAt']);
        self::assertIsArray($export['balanceLedgerLines']);
        self::assertCount(1, $export['balanceLedgerLines']);
        self::assertSame(['overtime_ratified', 3600, 'Ratified ana'], [$export['balanceLedgerLines'][0]['kind'], $export['balanceLedgerLines'][0]['seconds'], $export['balanceLedgerLines'][0]['reason']]);
        self::assertIsArray($export['timesheetEvents']);
        self::assertCount(1, $export['timesheetEvents']);
        self::assertSame(['rejected', 'approver', 'Fix Monday ana', $export['timesheets'][0]['id']], [$export['timesheetEvents'][0]['action'], $export['timesheetEvents'][0]['actorRole'], $export['timesheetEvents'][0]['comment'], $export['timesheetEvents'][0]['timesheetId']]);
        self::assertIsArray($export['calendarConnections']);
        self::assertCount(1, $export['calendarConnections']);
        $json = json_encode($export, JSON_THROW_ON_ERROR);
        foreach (['token-hash-ana', 'device-hash-ana', 'cred-ana'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $bobs = $this->personalData()->export($this->idOf($bob));
        self::assertSame([[], [], []], [$bobs['clientSessions'], $bobs['balanceLedgerLines'], $bobs['timesheetEvents']]);
    }

    public function testErasureAnonymisesTheEntriesByDefaultAndDeletesNoTime(): void
    {
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        $entry       = $this->entry($ana, 'task-1', '2026-11-02 09:00', 60, metadata: ['note' => 'Standup']);
        $this->imported($ana, 'task-1', '2026-11-03 09:00', 30, 'Imported text');
        $kept = $this->entry($bob, 'task-1', '2026-11-02 09:00', 60, metadata: ['note' => 'Bob note']);
        $this->seedPersonalData($ana);
        $this->seedPersonalData($bob, 'bob');
        // A session of ana's this manager has loaded is detached with the delete (bob's stays managed).
        $connection = $this->bundleManager()->getConnection();
        $anaToken   = $this->bundleManager()->find(ClientToken::class, (string) $connection->fetchOne('SELECT id FROM time_track_client_tokens WHERE token_hash = ?', ['token-hash-ana']));
        $bobToken   = $this->bundleManager()->find(ClientToken::class, (string) $connection->fetchOne('SELECT id FROM time_track_client_tokens WHERE token_hash = ?', ['token-hash-bob']));
        self::assertNotNull($anaToken);
        self::assertNotNull($bobToken);
        $seconds = (int) $connection->fetchOne('SELECT SUM(duration_seconds) FROM time_track_entries');

        $result = $this->personalData()->erase($this->idOf($ana));
        self::assertSame([1, 1, 1, 1, 2, 0, 1], [$result->clientSessions, $result->deviceSignIns, $result->monitoringUploads, $result->monitoringAcknowledgements, $result->entriesAnonymised, $result->entriesCleared, $result->calendarConnectionsKept]);
        self::assertFalse($this->bundleManager()->contains($anaToken));
        self::assertTrue($this->bundleManager()->contains($bobToken));

        self::assertSame($seconds, (int) $connection->fetchOne('SELECT SUM(duration_seconds) FROM time_track_entries'), 'no time is deleted');
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_entries'), 'no entry is deleted');
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_entries WHERE anonymised_at IS NOT NULL AND user_id IS NULL AND description IS NULL'));
        self::assertNull($this->query()->find($entry), 'an anonymised entry has no owner');
        self::assertSame([], $this->query()->list(TimeEntryCriteria::ofOwner($this->idOf($ana))));
        self::assertSame('Bob note', $this->query()->find($kept)?->note, 'another user is untouched');
        self::assertCount(1, $this->clientAccess()->sessionsOf($this->idOf($bob)));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_timesheets'), 'the working-time register stays');
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_calendar_connections'), 'calendar grants are revoked by the host first');
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM time_track_balance_ledger'), 'the balance ledger stays');
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertContains('TimeTrack personal data erased, but {count} calendar connection(s) of the user remain: call CalendarConnectionService::revokeAllFor() to revoke them.', $logger->messages());

        $again = $this->personalData()->erase($this->idOf($ana));
        self::assertSame([0, 0, 0, 0, 0, 0, 1], [$again->clientSessions, $again->deviceSignIns, $again->monitoringUploads, $again->monitoringAcknowledgements, $again->entriesAnonymised, $again->entriesCleared, $again->calendarConnectionsKept], 'idempotent');
    }

    public function testErasureOfTheFreeTextKeepsTheOwnerAndTheHours(): void
    {
        $ana      = $this->person('ana');
        $noted    = $this->entry($ana, 'task-1', '2026-11-02 09:00', 60, metadata: ['note' => 'Standup', 'auto_stopped' => true]);
        $onlyNote = $this->entry($ana, 'task-1', '2026-11-02 11:00', 60, metadata: ['note' => 'Short']);
        $plain    = $this->entry($ana, 'task-2', '2026-11-03 09:00', 30);
        $imported = $this->imported($ana, 'task-1', '2026-11-04 09:00', 30, 'Imported text');
        $loaded   = $this->bundleManager()->find(TimeEntry::class, $noted);
        self::assertInstanceOf(TimeEntry::class, $loaded);

        $result = $this->personalData()->erase($this->idOf($ana), TimeEntryErasure::FreeText);
        self::assertSame([3, 0], [$result->entriesCleared, $result->entriesAnonymised]);
        self::assertFalse($this->bundleManager()->contains($loaded), 'a loaded entry is detached, never flushed back');

        $records = $this->query()->list(TimeEntryCriteria::ofOwner($this->idOf($ana)), TimeEntrySort::StartedAtAscending);
        self::assertSame([$noted, $onlyNote, $plain, $imported], $this->ids($records), 'the owner stays');
        self::assertSame([null, null], [$records[0]->note, $records[3]->description]);
        self::assertSame(['auto_stopped' => true], $records[0]->metadata, 'the host\'s own metadata stays');
        self::assertSame([], $records[1]->metadata);
        self::assertSame(3 * 3600, $this->query()->totalSeconds(TimeEntryCriteria::ofOwner($this->idOf($ana))));
    }

    public function testErasureRefusesWhileTheTimerRunsOrForALookAlikeId(): void
    {
        $ana = $this->person('ana');
        $this->seedPersonalData($ana);
        $this->timers()->start($ana, 'task-1', ClientType::Web);

        try {
            $this->personalData()->erase($this->idOf($ana));
            self::fail('expected a refusal');
        } catch (PersonalDataErasureRefusedException $refused) {
            self::assertSame(PersonalDataErasureRefusedException::TIMER_RUNNING, $refused->errorCode);
        }
        self::assertCount(1, $this->clientAccess()->sessionsOf($this->idOf($ana)), 'nothing is written');

        $this->timers()->stop($ana);
        self::assertSame(1, $this->personalData()->erase($this->idOf($ana))->clientSessions, 'once the timer is saved');

        $this->expectException(PersonalDataErasureRefusedException::class);
        $this->personalData()->erase($this->idOf($ana) . "\t");
    }

    public function testTaskIdsCompareByteForByteOnACaseInsensitiveCollation(): void
    {
        $ana = $this->person('ana');
        $this->entry($ana, 'task-1', '2026-11-02 09:00', 60);
        $criteria = new TimeEntryCriteria(ownerIds: [$this->idOf($ana)], taskIds: ['TASK-1']);

        // On MySQL / MariaDB the task column folds case: the SQL matches and the PHP check drops the row.
        self::assertSame([], $this->query()->list($criteria));
        self::assertSame([], $this->query()->listAfter($criteria));
        self::assertSame(0, $this->query()->count($criteria));
        if ($this->bundleManager()->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertSame(1, (int) $this->bundleManager()->getConnection()->fetchOne("SELECT COUNT(*) FROM time_track_entries WHERE task_id = 'TASK-1'"));
        }
    }

    public function testRepeatedCallsForTwoUsersInOneKernelKeepNoState(): void
    {
        // Worker mode, kernel reset false: the same services answer many calls; nothing of one user's call leaks into the
        // next, and the memory stays flat (no entity is hydrated, nothing is memoised).
        [$ana, $bob] = [$this->person('ana'), $this->person('bob')];
        $this->entry($ana, 'task-1', '2026-11-02 09:00', 60);
        $this->entry($bob, 'task-2', '2026-11-02 09:00', 30);
        $this->bundleManager()->clear();
        // The debug test kernel keeps every log record, query and stopwatch period (Doctrine's debug middleware): emptied
        // per call, as a production kernel (no debug) or the profiler's reset would; the ports themselves keep nothing.
        $container = self::getContainer();
        $logger    = $container->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $debug = [];
        foreach (['doctrine.debug_data_holder', 'debug.stopwatch'] as $id) {
            $service = $container->has($id) ? $container->get($id) : null;
            if (is_object($service) && method_exists($service, 'reset')) {
                $debug[] = $service->reset(...);
            }
        }
        $step = function (int $i) use ($ana, $bob, $logger, $debug): void {
            $logger->records = [];
            foreach ($debug as $reset) {
                $reset();
            }
            $user    = $i % 2 === 0 ? $ana : $bob;
            $records = $this->query()->list(TimeEntryCriteria::ofOwner($this->idOf($user)));
            self::assertCount(1, $records);
            self::assertSame($this->idOf($user), $records[0]->entry->userId);
            self::assertSame($i % 2 === 0 ? 3600 : 1800, $this->query()->totalSeconds(TimeEntryCriteria::ofOwner($this->idOf($user))));
            $this->personalData()->export($this->idOf($user));
        };
        for ($i = 0; $i < 50; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        $start = memory_get_usage();
        for ($i = 0; $i < 300; ++$i) {
            $step($i);
        }
        gc_collect_cycles();
        $grown = memory_get_usage() - $start;

        self::assertLessThan(256 * 1024, $grown, sprintf('300 calls grew the memory by %d bytes', $grown));
        self::assertSame([], $this->bundleManager()->getUnitOfWork()->getIdentityMap());
    }

    /**
     * Sessions, a device, monitoring rows, a timesheet and a calendar connection of the user.
     */
    protected function seedPersonalData(UserInterface $user, string $tag = 'ana'): void
    {
        $owner = $this->owner($user);
        $now   = new DateTimeImmutable();
        $this->persist(new ClientToken('token-hash-' . $tag, new DateTimeImmutable('+1 day'), $owner, ClientType::Desktop));
        $device = new DeviceAuthorization('device-hash-' . $tag, 'user-hash-' . $tag, ClientType::Desktop, 'Laptop', [ClientScope::TimerWrite], new DateTimeImmutable('+10 minutes'), $now);
        $device->approve($owner, 'app-' . $tag);
        $this->persist($device);
        $this->persist(new MonitoringAcknowledgementRecord($owner, 3, $now, 'desktop', str_repeat('a', 64)));
        $this->persist(new MonitoringUploadIntent($owner, bin2hex(random_bytes(32)), str_repeat('b', 64), 'entry-1', $now, $now, 3, false, 'image/webp', 1024, 800, 600, str_repeat('c', 64), $now));
        $sheet = new Timesheet($owner, $this->idOf($user), 'org-a', Period::fromKey(TimesheetPeriodKind::Week, $now->format('o-\WW')), 'UTC', $now);
        $sheet->applyLock($now, TimesheetLockReason::Manual);
        $this->persist($sheet);
        // 1.35.0 (audit YI L2): a balance movement and the audit trail of the user's own timesheet.
        $this->persist(new BalanceLedgerLine($owner, $this->idOf($user), 'org-a', $now->format('Y-m-d'), LedgerKind::OvertimeRatified, 3600, $now, null, 'Ratified ' . $tag, $sheet, null, 'manager-1'));
        $this->persist(new TimesheetEvent($sheet, 1, TimesheetAction::Rejected, TimesheetStatus::Submitted, TimesheetStatus::Rejected, 'manager-1', TimesheetActorRole::Approver, AuditClient::Web, $now, null, 'Fix Monday ' . $tag));
        $this->persist(new CalendarConnection($owner, 'org-a', CalendarProvider::Google, CalendarConnectionMode::Server, 'cred-' . $tag, 'v1', $now));
    }

    /**
     * A closed live entry; returns its id.
     *
     * @param array<string, mixed> $metadata
     */
    protected function entry(UserInterface $user, string $task, string $start, int $minutes, string $scope = 'org-a', string $board = 'board-1', array $metadata = []): string
    {
        $startedAt = new DateTimeImmutable($start . ' UTC');
        $entry     = new TimeEntry($this->owner($user), $task, 'Task ' . $task, $board, $startedAt, $startedAt->modify('+' . $minutes . ' minutes'), $minutes * 60, TimeEntrySource::Web, $metadata === [] ? null : $metadata);
        $entry->assignScopeKey($scope);
        $this->persist($entry);

        return $entry->getId();
    }

    /**
     * An imported entry (read-only, billable, with a description); returns its id.
     */
    protected function imported(UserInterface $user, string $task, string $start, int $minutes, ?string $description): string
    {
        $startedAt = new DateTimeImmutable($start . ' UTC');
        $entry     = TimeEntry::imported($this->owner($user), new ImportedTimeEntryData('clickup:dir', 'ext-' . bin2hex(random_bytes(4)), $task, 'Task ' . $task, 'board-1', $startedAt, $startedAt->modify('+' . $minutes . ' minutes'), $minutes * 60, true, $description));
        $entry->assignScopeKey('org-a');
        $this->persist($entry);

        return $entry->getId();
    }

    protected function persist(object $row): void
    {
        $this->bundleManager()->persist($row);
        $this->bundleManager()->flush();
    }

    protected function owner(UserInterface $user): object
    {
        $resolver = self::getContainer()->get(UserReferenceResolverInterface::class);
        self::assertInstanceOf(UserReferenceResolverInterface::class, $resolver);

        return $resolver->reference($user);
    }

    protected function bundleManager(): EntityManagerInterface
    {
        $container = self::getContainer();
        $manager   = $container->has(TimeTrackEntityManager::class) ? $container->get(TimeTrackEntityManager::class) : $this->entityManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * @param list<TimeEntryRecord> $records
     *
     * @return list<string>
     */
    protected function ids(array $records): array
    {
        return array_map(static fn (TimeEntryRecord $record): string => $record->entry->id, $records);
    }

    protected function query(): TimeEntryQueryInterface
    {
        $query = self::getContainer()->get(TimeEntryQueryInterface::class);
        self::assertInstanceOf(TimeEntryQueryInterface::class, $query);

        return $query;
    }

    protected function timerQuery(): RunningTimerQueryInterface
    {
        $query = self::getContainer()->get(RunningTimerQueryInterface::class);
        self::assertInstanceOf(RunningTimerQueryInterface::class, $query);

        return $query;
    }

    protected function clientAccess(): ClientAccessQueryInterface
    {
        $query = self::getContainer()->get(ClientAccessQueryInterface::class);
        self::assertInstanceOf(ClientAccessQueryInterface::class, $query);

        return $query;
    }

    protected function personalData(): TimeTrackPersonalDataInterface
    {
        $service = self::getContainer()->get(TimeTrackPersonalDataInterface::class);
        self::assertInstanceOf(TimeTrackPersonalDataInterface::class, $service);

        return $service;
    }

    protected function timers(): TimerService
    {
        $timers = self::getContainer()->get(TimerService::class);
        self::assertInstanceOf(TimerService::class, $timers);

        return $timers;
    }
}
