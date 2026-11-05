<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientIdempotencyStore;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ClientWorkingTimeContext;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Repository\TaskEffortRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryActiveTimerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimeEntryRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetEventRepository;
use Nowo\TimeTrackBundle\Tests\Support\InMemoryTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Support\RecordingLogger;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAuditTrail;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetSnapshotBuilder;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\WorkingTime\ConfigOvertimePolicy;
use Nowo\TimeTrackBundle\WorkingTime\TaskTimeWorkedTimeSource;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 1.13: defensive branches of the working-time client pieces.
 */
final class WorkingTimeClientEdgesTest extends TestCase
{
    public function testACorruptIdempotencyItemIsIgnored(): void
    {
        $cache = new ArrayAdapter();
        $store = new ClientIdempotencyStore($cache);
        $store->save('7', 'k', 'h', 200, ['ok' => true]);
        self::assertSame(['status' => 200, 'body' => ['ok' => true]], $store->find('7', 'k', 'h'));
        self::assertFalse($store->find('7', 'k', 'other'));
        self::assertNull($store->find('8', 'k', 'h'));

        foreach ($cache->getValues() as $key => $value) {
            $item = $cache->getItem($key);
            $item->set('garbage');
            $cache->save($item);
        }
        self::assertNull($store->find('7', 'k', 'h'));
    }

    public function testAFailingEffortStoreLeavesTheFieldsUnknown(): void
    {
        $logger  = new RecordingLogger();
        $failing = new class implements TaskEffortRepositoryInterface {
            public function loggedSecondsByTask(array $taskIds): array
            {
                throw new RuntimeException('down');
            }
        };
        $timer     = new TimerService(new StubTaskProvider(), new InMemoryActiveTimerRepository(), new InMemoryTimeEntryRepository(), new EventDispatcher());
        $presenter = new ClientTaskPresenter($timer, null, $logger, $failing);

        $task = $presenter->task(new TaskReference('t', 'T', estimateSeconds: 3600), Request::create('https://host.example/'));
        self::assertSame(3600, $task['estimateSeconds']);
        self::assertNull($task['loggedSeconds']);
        self::assertSame(['task_effort_failed'], array_column(array_column($logger->records, 'context'), 'action'));
        $given = $presenter->task(new TaskReference('t', 'T', estimateSeconds: 3600, loggedSeconds: 5000), Request::create('https://host.example/'));
        self::assertSame(0, $given['remainingSeconds'], 'a provider total wins and remaining never goes below zero');
        self::assertNull($presenter->task(new TaskReference('t', 'T', estimateSeconds: -1, loggedSeconds: -5), Request::create('https://host.example/'))['estimateSeconds']);
    }

    public function testASummaryRaceAnswersNullAndOrphanEntriesAreLocked(): void
    {
        $user       = new TestUser('7', 'ana@example.com');
        $timesheets = new InMemoryTimesheetRepository();
        $events     = new InMemoryTimesheetEventRepository();
        $scopes     = new FixedScopeProvider(['7' => ['org-a']]);
        $access     = new TimesheetAccessGuard($scopes, new StubTimesheetApproverProvider());
        $resolver   = new TimesheetTimezoneResolver($scopes);
        $entries    = new InMemoryTimeEntryRepository();
        $service    = new TimesheetService($timesheets, $events, new TimesheetAuditTrail($events, $timesheets), $access, new TimesheetSnapshotBuilder($entries), $resolver, new InMemoryActiveTimerRepository());
        $balance    = new WorkingTimeBalanceService(new FixedWorkingCalendar(), new TaskTimeWorkedTimeSource($entries, $resolver), new ConfigOvertimePolicy(), new InMemoryBalanceLedgerRepository(), $timesheets, $resolver, new MockClock());
        $guard      = new PeriodLockGuard($timesheets);
        $context    = new ClientWorkingTimeContext($service, $balance, $access, $guard);

        $timesheets->failSave = $this->createStub(UniqueConstraintViolationException::class);
        self::assertNull($context->timesheetSummary($user));
        self::assertNull($context->workdayProvider());
        self::assertSame(['workday' => false, 'timesheets' => true, 'balance' => true], $context->features($user));
        self::assertNull($context->scope($user, 'org-x'));

        $orphan = new TimeEntry(new stdClass(), 't', 'T', null, new DateTimeImmutable(), new DateTimeImmutable(), 0, TimeEntrySource::Web);
        self::assertSame([$orphan->getId() => true], $context->blockedEntryIds([$orphan]));
        self::assertSame([], (new PeriodLockGuard($timesheets, false))->blockedEntryIds([$orphan]));
    }
}
