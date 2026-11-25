<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Command\CheckAllocationCommand;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\TimesheetLockReason;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Subject\ActivityTypeCatalog;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Since 2.1.0 (Beacon spec 256, design §12.2): `nowo:time-track:entries:check-allocation` reports, read-only, the rows a
 * host back-fill wrote against the allocation column contract, against the test database.
 */
final class CheckAllocationCommandTest extends FunctionalTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel(self::timeTrackConfig(), allocationProviders: true);
    }

    protected static function timeTrackConfig(): array
    {
        return ['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => StubTimesheetApproverProvider::class]];
    }

    public function testACleanDatabasePasses(): void
    {
        $ok = $this->entry('task-1', '');
        $this->allocate('time_track_entries', $ok, 'customer', 'c-1', 'dev');
        $this->entry('task-1', 'org-a');

        $tester = $this->runCheck([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Checked 1 allocated row(s); 0 allocated entries sit in a locked or submitted period.', $display);
        self::assertStringContainsString('Every allocated row meets the TimeTrack 2.1 column contract.', $display);
    }

    public function testEveryFindingIsReported(): void
    {
        $rows = [
            'half'     => [$this->entry('task-1', 'org-a'), 'customer', null, null],
            'idOnly'   => [$this->entry('task-1', 'org-a'), null, 'c-1', null],
            'bad'      => [$this->entry('task-1', 'org-a'), 'Customer', 'c-1', null],
            'badId'    => [$this->entry(null, 'org-a'), 'customer', ' c-1', null],
            'unknown'  => [$this->entry(null, 'org-a'), 'vendor', 'v-1', null],
            'withTask' => [$this->entry('task-1', 'org-a'), 'internal', 'i-1', null],
            'alone'    => [$this->entry(null, 'org-a'), 'internal', 'i-1', 'meeting'],
            'badKey'   => [$this->entry('task-1', 'org-b'), null, null, 'Bad Key'],
            'noKey'    => [$this->entry('task-1', 'org-b'), null, null, 'nope'],
            'retired'  => [$this->entry('task-1', null), null, null, 'legacy'],
        ];
        foreach ($rows as [$id, $type, $subjectId, $key]) {
            $this->allocate('time_track_entries', $id, $type, $subjectId, $key);
        }
        $timer = $this->timer();
        $this->allocate('time_track_active_timers', $timer, 'customer', null, 'legacy');

        $tester = $this->runCheck(['--fix-report' => true, '--batch' => '2']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Checked 11 allocated row(s)', $display);
        foreach ([
            CheckAllocationCommand::HALF_PAIR         => 3,
            CheckAllocationCommand::MALFORMED_SUBJECT => 2,
            CheckAllocationCommand::UNKNOWN_TYPE      => 1,
            CheckAllocationCommand::TASK_DISALLOWED   => 1,
            CheckAllocationCommand::MALFORMED_KEY     => 1,
            CheckAllocationCommand::UNKNOWN_KEY       => 1,
            CheckAllocationCommand::INACTIVE_KEY      => 2,
        ] as $code => $count) {
            self::assertMatchesRegularExpression('/\b' . $code . '\s+' . $count . '\s/', $display, $code);
        }
        self::assertStringContainsString($rows['withTask'][0], $display);
        self::assertStringNotContainsString($rows['alone'][0], $display, 'a subject that refuses tasks is fine alone');
        self::assertMatchesRegularExpression('/active_timers\s+' . $timer . '\s+half_pair, activity_type_inactive/', $display);
        self::assertStringContainsString('10 row(s) break the TimeTrack 2.1 allocation column contract', $display);
    }

    public function testWithoutFixReportOnlyTheCountsAreListed(): void
    {
        $id = $this->entry('task-1', 'org-a');
        $this->allocate('time_track_entries', $id, 'vendor', 'v-1', null);

        $tester = $this->runCheck([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('unknown_subject_type', $tester->getDisplay());
        self::assertStringNotContainsString($id, $tester->getDisplay());
    }

    public function testTheScopeOptionKeepsOneTenant(): void
    {
        $a = $this->entry('task-1', 'org-a');
        $b = $this->entry('task-1', 'org-b');
        $this->allocate('time_track_entries', $a, 'vendor', 'v-1', null);
        $this->allocate('time_track_entries', $b, 'customer', 'c-1', 'dev');

        $onlyB = $this->runCheck(['--scope' => 'org-b']);
        self::assertSame(Command::SUCCESS, $onlyB->getStatusCode());
        self::assertStringContainsString('Checked 1 allocated row(s)', $onlyB->getDisplay());

        $onlyA = $this->runCheck(['--scope' => 'org-a', '--fix-report' => true]);
        self::assertSame(Command::FAILURE, $onlyA->getStatusCode());
        self::assertStringContainsString($a, $onlyA->getDisplay());
        self::assertStringNotContainsString($b, $onlyA->getDisplay());
    }

    public function testAnUnusableScopeIsInvalid(): void
    {
        $tester = $this->runCheck(['--scope' => ' org-a']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--scope must be a usable scope key.', $tester->getDisplay());
    }

    public function testAllocatedEntriesInALockedPeriodAreCounted(): void
    {
        $locked = $this->entry('task-1', '', '2026-10-07 09:00:00');
        $other  = $this->entry('task-1', '', '2026-10-07 11:00:00');
        $open   = $this->entry('task-1', '', '2026-10-14 09:00:00');
        $this->allocate('time_track_entries', $locked, 'customer', 'c-1', null);
        $this->allocate('time_track_entries', $other, null, null, 'dev');
        $this->allocate('time_track_entries', $open, 'customer', 'c-1', null);
        $timesheet = new Timesheet($this->user, (string) $this->user->getId(), '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W41'), 'UTC', new DateTimeImmutable('2026-10-12 08:00:00'));
        $timesheet->applyLock(new DateTimeImmutable('2026-10-12 08:00:00'), TimesheetLockReason::Manual);
        (new DoctrineOrmTimesheetRepository($this->bundleEntityManager()))->save($timesheet);

        $tester = $this->runCheck([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Checked 3 allocated row(s); 2 allocated entries sit in a locked or submitted period.', $tester->getDisplay());

        $this->bundleEntityManager()->getConnection()->executeStatement('UPDATE time_track_entries SET subject_type = NULL, subject_id = NULL, activity_type_key = NULL WHERE id = ?', [$other]);
        self::assertStringContainsString('1 allocated entry sits in a locked or submitted period.', $this->runCheck([])->getDisplay());
    }

    public function testWithoutLockGuardNothingIsCountedAsLocked(): void
    {
        $id = $this->entry('task-1', '', '2026-10-07 09:00:00');
        $this->allocate('time_track_entries', $id, 'customer', 'c-1', null);
        $timesheet = new Timesheet($this->user, (string) $this->user->getId(), '', Period::fromKey(TimesheetPeriodKind::Week, '2026-W41'), 'UTC', new DateTimeImmutable('2026-10-12 08:00:00'));
        $timesheet->applyLock(new DateTimeImmutable('2026-10-12 08:00:00'), TimesheetLockReason::Manual);
        (new DoctrineOrmTimesheetRepository($this->bundleEntityManager()))->save($timesheet);
        $container = self::getContainer();
        $subjects  = $container->get(TimeSubjectRegistry::class);
        $types     = $container->get(ActivityTypeCatalog::class);
        self::assertInstanceOf(TimeSubjectRegistry::class, $subjects);
        self::assertInstanceOf(ActivityTypeCatalog::class, $types);

        $tester = new CommandTester(new CheckAllocationCommand($this->bundleEntityManager(), $subjects, $types));
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Checked 1 allocated row(s); 0 allocated entries sit in a locked or submitted period.', $tester->getDisplay());
    }

    /**
     * Runs the command.
     *
     * @param array<string, mixed> $input
     */
    private function runCheck(array $input): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('nowo:time-track:entries:check-allocation'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * Stores a live entry of the test user and answers its id.
     */
    private function entry(?string $taskId, ?string $scopeKey, string $start = '2026-11-02 09:00:00'): string
    {
        $startedAt = new DateTimeImmutable($start);
        $entry     = new TimeEntry($this->user, $taskId, $taskId === null ? '' : 'Task', null, $startedAt, $startedAt->modify('+1 hour'), 3600, TimeEntrySource::Web);
        if ($scopeKey !== null) {
            $entry->assignScopeKey($scopeKey);
        }
        $em = $this->bundleEntityManager();
        $em->persist($entry);
        $em->flush();

        return $entry->getId();
    }

    /**
     * Stores a running timer of another user and answers its id.
     */
    private function timer(): string
    {
        $timer = new ActiveTimer($this->createUser('other@example.com'), 'task-1', 'Task', null, ClientType::Web);
        $em    = $this->bundleEntityManager();
        $em->persist($timer);
        $em->flush();

        return $timer->getId();
    }

    /**
     * Writes the allocation columns by SQL, as a host back-fill would (no entity checks).
     */
    private function allocate(string $table, string $id, ?string $type, ?string $subjectId, ?string $key): void
    {
        $this->bundleEntityManager()->getConnection()->executeStatement('UPDATE ' . $table . ' SET subject_type = ?, subject_id = ?, activity_type_key = ? WHERE id = ?', [$type, $subjectId, $key, $id]);
    }
}
