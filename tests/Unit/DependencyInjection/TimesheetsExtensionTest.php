<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetEventRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRetentionRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Nowo\TimeTrackBundle\WorkingTime\ConfigOvertimePolicy;
use Nowo\TimeTrackBundle\WorkingTime\DerivedWorkingCalendar;
use Nowo\TimeTrackBundle\WorkingTime\OvertimePolicyInterface;
use Nowo\TimeTrackBundle\WorkingTime\TaskTimeWorkedTimeSource;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeSourceInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 1.13: `timesheets` configuration, the always-mapped stores and the period lock wiring.
 */
final class TimesheetsExtensionTest extends TestCase
{
    public function testDefaultsKeepTimesheetsOffWithTheStoresMapped(): void
    {
        $container = $this->load([]);
        $config    = $container->getParameter('nowo_time_track.timesheets');

        self::assertIsArray($config);
        self::assertFalse($config['enabled']);
        self::assertSame('provider', $config['scope_mode']);
        self::assertSame('week', $config['period']);
        self::assertSame('UTC', $config['default_timezone']);
        self::assertFalse($config['submit_requires_period_end']);
        self::assertNull($config['auto_lock_after_days']);
        self::assertFalse($config['allow_self_approval']);
        self::assertFalse($config['admin_roles_view']);
        self::assertSame([], $config['reopen_roles']);
        self::assertFalse($config['approver_sees_notes']);
        self::assertFalse($config['audit_blocked_edits']);
        self::assertSame(4, $config['retention_years']);
        self::assertFalse($container->getParameter('nowo_time_track.timesheets.enabled'));

        self::assertSame(DoctrineOrmTimesheetRepository::class, (string) $container->getAlias(TimesheetRepositoryInterface::class));
        self::assertSame(DoctrineOrmTimesheetRepository::class, (string) $container->getAlias(TimesheetRetentionRepositoryInterface::class));
        self::assertSame(DoctrineOrmTimesheetEventRepository::class, (string) $container->getAlias(TimesheetEventRepositoryInterface::class));
        self::assertSame(DoctrineOrmBalanceLedgerRepository::class, (string) $container->getAlias(BalanceLedgerRepositoryInterface::class));
        self::assertFalse($container->hasAlias(TimesheetScopeProviderInterface::class));
        self::assertFalse($container->getDefinition(PeriodLockGuard::class)->getArgument('$enabled'));
        self::assertSame(PeriodLockGuard::class, (string) $container->getDefinition(TimerService::class)->getArgument('$periodLockGuard'));
        self::assertSame(PeriodLockGuard::class, (string) $container->getDefinition(ImportedTimeEntryWriter::class)->getArgument('$periodLockGuard'));
        self::assertSame(PeriodLockGuard::class, (string) $container->getDefinition(TeamAccessGuard::class)->getArgument('$periodLockGuard'));
        $scope = $container->getDefinition(TimerService::class)->getArgument('$scopeProvider');
        self::assertInstanceOf(Reference::class, $scope);
        self::assertSame(TimesheetScopeProviderInterface::class, (string) $scope);
    }

    public function testSingleScopeMode(): void
    {
        $container = $this->load(['timesheets' => ['enabled' => true, 'scope_mode' => 'single', 'approver_provider' => 'app.approvers', 'period' => 'month', 'default_timezone' => 'Europe/Madrid', 'auto_lock_after_days' => 10, 'retention_years' => 6, 'reopen_roles' => ['ROLE_PAYROLL']]]);

        self::assertSame(SingleScopeProvider::class, (string) $container->getAlias(TimesheetScopeProviderInterface::class));
        self::assertTrue($container->getDefinition(PeriodLockGuard::class)->getArgument('$enabled'));
        self::assertTrue($container->getDefinition(PeriodLockGuard::class)->isPublic());
    }

    public function testProviderMode(): void
    {
        $container = $this->load(['timesheets' => ['enabled' => true, 'scope_provider' => 'app.scopes', 'approver_provider' => 'app.approvers']]);

        self::assertSame('app.scopes', (string) $container->getAlias(TimesheetScopeProviderInterface::class));
    }

    public function testWorkingTimeDefaultsAndHostServices(): void
    {
        $container = $this->load([]);
        $config    = $container->getParameter('nowo_time_track.working_time');
        self::assertIsArray($config);
        self::assertFalse($config['enabled']);
        self::assertSame(['daily_mode' => 'expected', 'ordinary_max_seconds' => 32_400, 'weekly_mode' => 'expected', 'weekly_max_seconds' => 144_000, 'annual_cap_seconds' => 288_000, 'ratify_on_approval' => true], $config['overtime']);
        self::assertSame(DerivedWorkingCalendar::class, (string) $container->getAlias(WorkingCalendarProviderInterface::class));
        self::assertSame(TaskTimeWorkedTimeSource::class, (string) $container->getAlias(WorkedTimeSourceInterface::class));
        self::assertSame(ConfigOvertimePolicy::class, (string) $container->getAlias(OvertimePolicyInterface::class));
        self::assertFalse($container->getDefinition(WorkingTimeBalanceService::class)->getArgument('$enabled'));
        self::assertNull($container->getDefinition(TimesheetAccessGuard::class)->getArgument('$approverProvider'));
        self::assertTrue($container->getDefinition(BalanceLedger::class)->isPublic());
        self::assertSame('UTC', $container->getDefinition(TimesheetTimezoneResolver::class)->getArgument('$defaultTimezone'));

        $host = $this->load(['working_time' => ['enabled' => true, 'calendar_provider' => 'app.calendar', 'worked_time_source' => 'app.register', 'overtime_policy' => 'app.convenio', 'balance' => ['start_date' => '2026-01-01'], 'overtime' => ['daily_mode' => 'none', 'weekly_mode' => 'legal_max']]]);
        self::assertSame('app.calendar', (string) $host->getAlias(WorkingCalendarProviderInterface::class));
        self::assertSame('app.register', (string) $host->getAlias(WorkedTimeSourceInterface::class));
        self::assertSame('app.convenio', (string) $host->getAlias(OvertimePolicyInterface::class));
        self::assertSame('2026-01-01', $host->getDefinition(WorkingTimeBalanceService::class)->getArgument('$balanceStartDate'));
        self::assertSame('none', $host->getDefinition(ConfigOvertimePolicy::class)->getArgument('$dailyMode'));
        self::assertSame(SingleScopeProvider::class, (string) $host->getAlias(TimesheetScopeProviderInterface::class), 'working time alone: one scope');

        $scoped = $this->load(['working_time' => ['enabled' => true], 'timesheets' => ['scope_provider' => 'app.scopes', 'approver_provider' => 'app.approvers']]);
        self::assertSame('app.scopes', (string) $scoped->getAlias(TimesheetScopeProviderInterface::class));
        self::assertInstanceOf(Reference::class, $scoped->getDefinition(TimesheetAccessGuard::class)->getArgument('$approverProvider'));
    }

    public function testABadStartDateIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('start_date');

        $this->load(['working_time' => ['balance' => ['start_date' => '2026-13-01']]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'no scope provider' => [['enabled' => true, 'approver_provider' => 'a'], 'scope_provider is required'];
        yield 'no approver provider' => [['enabled' => true, 'scope_mode' => 'single'], 'approver_provider is required'];
        yield 'self approval' => [['allow_self_approval' => true], 'cannot be enabled in 1.13'];
        yield 'zone' => [['default_timezone' => 'Mars/Olympus'], 'IANA time zone'];
        yield 'retention' => [['retention_years' => 3], 'retention_years'];
        yield 'auto-lock' => [['auto_lock_after_days' => 0], 'auto_lock_after_days'];
    }

    /**
     * @param array<string, mixed> $timesheets
     */
    #[DataProvider('invalidConfigs')]
    public function testIncoherentCombinationsAreRefused(array $timesheets, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load(['timesheets' => $timesheets]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([['user_class' => User::class] + $config], $container);

        return $container;
    }
}
