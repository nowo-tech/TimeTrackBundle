<?php

declare(strict_types=1);

/*
 * 1.27.0 (Beacon spec 239): one worker process of the load-readiness database tests. It boots the functional test
 * kernel on TIME_TRACK_TEST_DATABASE_URL (the schema is created by the test), waits for the start signal (a line on
 * STDIN) so every worker runs at the same moment, runs one action N times and prints a JSON report:
 * {"ok": n, "refused": {code: n}, "errors": {class: n}, "detached": n}.
 *
 * Usage: php tests/Database/worker.php <action> <count> <ownerId> [<approverId>]
 * Actions: `ledger`, `start`, `revoke` (1.34.0: revoke every stored client session by its id, as the owner),
 * `start-without-task` (1.36.0: start a timer without a task and keep it; only one may ever run), `correct` (1.40.0:
 * read the only entry by id and add 1 to its metadata counter `n` through `TimeEntryOperationsInterface::correct()` with
 * the version read), `revoke-session` (1.40.0: `revoke` through `ClientSessionRevokerInterface::revokeSession()` by id).
 */

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Client\ClientSessionRevokerInterface;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Entry\TimeEntryCorrection;
use Nowo\TimeTrackBundle\Entry\TimeEntryOperationsInterface;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\LedgerRefusedException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\Query\TimeEntryQueryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\DependencyInjection\ContainerInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $action, $count, $ownerId] = $argv + [null, '', '0', ''];
$approverId                   = $argv[4] ?? '';

$kernel = new FunctionalKernel([
    'timesheets'   => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
    'working_time' => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
    'entries'      => ['allow_without_task' => true],
], debug: false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) {
    throw new LogicException('The functional kernel has no test container.');
}
$em        = $container->get('doctrine.orm.entity_manager');
$scopes    = $container->get(FixedScopeProvider::class);
$approvers = $container->get(StubTimesheetApproverProvider::class);
$ledger    = $container->get(BalanceLedger::class);
$timers    = $container->get(TimerService::class);
$revoker   = $container->get(ClientTokenRevoker::class);
$sessions2 = $container->get(ClientSessionRevokerInterface::class);
$entryOps  = $container->get(TimeEntryOperationsInterface::class);
$query     = $container->get(TimeEntryQueryInterface::class);
if (!$em instanceof EntityManagerInterface || !$scopes instanceof FixedScopeProvider || !$approvers instanceof StubTimesheetApproverProvider || !$ledger instanceof BalanceLedger || !$timers instanceof TimerService || !$revoker instanceof ClientTokenRevoker || !$sessions2 instanceof ClientSessionRevokerInterface || !$entryOps instanceof TimeEntryOperationsInterface || !$query instanceof TimeEntryQueryInterface) {
    throw new LogicException('The functional kernel lacks a service of the worker.');
}
$owner    = $em->find(AppUser::class, (int) $ownerId);
$approver = $approverId === '' ? null : $em->find(AppUser::class, (int) $approverId);
if (!$owner instanceof AppUser || !$approver instanceof AppUser) {
    throw new LogicException('Unknown owner or approver.');
}
$scopes->memberships  = [$ownerId => ['org-a'], $approverId => ['org-a']];
$approvers->approvers = ['org-a' => [$approverId => [$ownerId]]];

$report = ['ok' => 0, 'refused' => [], 'errors' => [], 'detached' => 0];
fgets(\STDIN); // The start signal.
// 1.34.0 `revoke`: every process revokes the same sessions (ids read once, before the loop), in the same order.
$sessions = $action === 'revoke' || $action === 'revoke-session' ? array_map(strval(...), $em->getConnection()->fetchFirstColumn('SELECT id FROM time_track_client_tokens ORDER BY id')) : [];
$entryId  = $action === 'correct' ? (string) $em->getConnection()->fetchOne('SELECT id FROM time_track_entries') : '';
for ($i = 0; $i < (int) $count; ++$i) {
    try {
        if ($action === 'correct') {
            // Read, then write with the version read: another process may have written in between (`conflict`).
            $record = $query->find($entryId) ?? throw new LogicException('The entry is gone.');
            $n      = $record->metadata['n'] ?? 0;
            try {
                $entryOps->correct($ownerId, $entryId, $record->version(), new TimeEntryCorrection(metadata: ['n' => (is_int($n) ? $n : 0) + 1]));
                ++$report['ok'];
            } catch (TimeEntryCorrectionException $refused) {
                $report['refused'][$refused->errorCode] = ($report['refused'][$refused->errorCode] ?? 0) + 1;
            } catch (TransientStorageFailure) {
                $report['refused']['transient'] = ($report['refused']['transient'] ?? 0) + 1;
            }
            $report['detached'] += $em->contains($owner) ? 0 : 1;

            continue;
        }
        if ($action === 'revoke-session') {
            if ($sessions2->revokeSession($sessions[$i] ?? '', $ownerId)->isRevoked()) {
                ++$report['ok'];
            } else {
                $report['refused']['not_found'] = ($report['refused']['not_found'] ?? 0) + 1;
            }

            continue;
        }
        if ($action === 'revoke') {
            if ($revoker->revokeById($sessions[$i] ?? '', $owner)->isRevoked()) {
                ++$report['ok'];
            } else {
                $report['refused']['not_found'] = ($report['refused']['not_found'] ?? 0) + 1;
            }
            $report['detached'] += $em->contains($owner) ? 0 : 1;

            continue;
        }
        if ($action === 'start-without-task') {
            try {
                $timers->start($owner, null, ClientType::Desktop, [], 'worker ' . getmypid(), 'org-a');
            } catch (ActiveTimerConflictException) {
                $report['refused']['running'] = ($report['refused']['running'] ?? 0) + 1;
                $report['detached'] += $em->contains($owner) ? 0 : 1;

                continue;
            }
            ++$report['ok'];

            continue;
        }
        if ($action === 'ledger') {
            $ledger->add($approver, $owner, 'org-a', LedgerKind::Adjustment, 60, '2026-10-01', 'worker ' . getmypid() . ' line ' . $i);
        } elseif ($action === 'start') {
            try {
                $timers->start($owner, 'task-1', ClientType::Desktop);
            } catch (ActiveTimerConflictException) {
                $report['refused']['running'] = ($report['refused']['running'] ?? 0) + 1;
                // Up to 1.26 a lost start reset the application's manager: its user was detached.
                $report['detached'] += $em->contains($owner) ? 0 : 1;
            }
            $timers->stop($owner);
        }
        ++$report['ok'];
    } catch (LedgerRefusedException $refused) {
        $report['refused'][$refused->getMessage()] = ($report['refused'][$refused->getMessage()] ?? 0) + 1;
    } catch (Throwable $error) {
        $report['errors'][$error::class] = ($report['errors'][$error::class] ?? 0) + 1;
        if (!$em->isOpen()) {
            break; // A closed manager would fail every later call of this process.
        }
    }
}

echo json_encode($report, \JSON_THROW_ON_ERROR), "\n";
