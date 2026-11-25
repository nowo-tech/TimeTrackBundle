<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\Command\CheckAllocationCommand;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeSubjectProvidersPass;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\PersonalData\DoctrineTimeTrackPersonalData;
use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Query\DoctrineTimeTrackQuery;
use Nowo\TimeTrackBundle\Query\TimeEntryTotalsQueryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\ActivityTypeCatalog;
use Nowo\TimeTrackBundle\Subject\AllocationGuard;
use Nowo\TimeTrackBundle\Subject\AllocationLabeler;
use Nowo\TimeTrackBundle\Subject\ScopeTimeSubjectProvider;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabelResolver;
use Nowo\TimeTrackBundle\Subject\TimeSubjectProviderInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 2.1.0 (Beacon spec 256): `entries.require_allocation`, `require_activity_type`, `subjects.*` and their wiring.
 */
final class TimeSubjectsExtensionTest extends TestCase
{
    public function testConfigurationDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);

        self::assertFalse($config['entries']['require_allocation']);
        self::assertFalse($config['entries']['require_activity_type']);
        self::assertSame(['enabled' => false, 'type' => 'organisation'], $config['entries']['subjects']['scope_subject']);
        self::assertSame(60, $config['entries']['subjects']['search']['max_per_minute']);
        self::assertSame('/api/time-track/timer/allocate', $config['clients']['routes']['timer_allocate']['path']);
        self::assertSame('nowo_time_track_api_subjects_search', $config['clients']['routes']['subjects_search']['name']);
        self::assertSame('/api/time-track/activity-types', $config['clients']['routes']['activity_types']['path']);
    }

    public function testDefaultWiring(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->getParameter('nowo_time_track.entries.require_allocation'));
        self::assertFalse($container->getParameter('nowo_time_track.entries.require_activity_type'));
        self::assertSame(60, $container->getParameter('nowo_time_track.entries.subjects.search.max_per_minute'));
        self::assertFalse($container->hasDefinition(ScopeTimeSubjectProvider::class));
        self::assertTrue($container->hasDefinition(TimeSubjectRegistry::class));
        self::assertSame([], $container->getDefinition(TimeSubjectRegistry::class)->getArgument('$allowsTask'));
        self::assertTrue($container->getDefinition(TimeSubjectLabelResolver::class)->isPublic());
        self::assertSame([TimeSubjectProvidersPass::TAG => [[]]], $container->getAutoconfiguredInstanceof()[TimeSubjectProviderInterface::class]->getTags());

        $catalog = $container->getDefinition(ActivityTypeCatalog::class)->getArgument('$provider');
        self::assertInstanceOf(Reference::class, $catalog);
        self::assertSame(TimeSubjectProvidersPass::ACTIVITY_TYPES_SLOT, (string) $catalog);

        $guard = $container->getDefinition(AllocationGuard::class);
        self::assertFalse($guard->getArgument('$requireAllocation'));
        self::assertFalse($guard->getArgument('$requireActivityType'));
        self::assertFalse($guard->getArgument('$allowWithoutTask'));
        self::assertSame(AllocationGuard::class, (string) $container->getDefinition(TimerService::class)->getArgument('$allocationGuard'));
        self::assertSame(TimeSubjectLabelResolver::class, (string) $container->getDefinition(AllocationLabeler::class)->getArgument('$labels'));
        self::assertSame(AllocationLabeler::class, (string) $container->getDefinition(DoctrineTimeTrackPersonalData::class)->getArgument('$allocations'));

        $command = $container->getDefinition(CheckAllocationCommand::class);
        self::assertTrue($command->hasTag('console.command'));
        self::assertSame(PeriodLockGuard::class, (string) $command->getArgument('$lockGuard'));
        self::assertSame(DoctrineTimeTrackQuery::class, (string) $container->getAlias(TimeEntryTotalsQueryInterface::class));
        self::assertTrue($container->getAlias(TimeEntryTotalsQueryInterface::class)->isPublic());
    }

    public function testRequirementsAndScopeSubject(): void
    {
        $container = $this->load(['entries' => [
            'allow_without_task'    => true,
            'require_allocation'    => true,
            'require_activity_type' => true,
            'subjects'              => ['scope_subject' => ['enabled' => true, 'type' => 'tenant'], 'search' => ['max_per_minute' => 5]],
        ]]);

        self::assertTrue($container->getParameter('nowo_time_track.entries.require_allocation'));
        self::assertTrue($container->getParameter('nowo_time_track.entries.require_activity_type'));
        self::assertSame(5, $container->getParameter('nowo_time_track.entries.subjects.search.max_per_minute'));
        $guard = $container->getDefinition(AllocationGuard::class);
        self::assertTrue($guard->getArgument('$requireAllocation'));
        self::assertTrue($guard->getArgument('$requireActivityType'));
        self::assertTrue($guard->getArgument('$allowWithoutTask'));

        $scope = $container->getDefinition(ScopeTimeSubjectProvider::class);
        self::assertSame([['type' => 'tenant']], $scope->getTag(TimeSubjectProvidersPass::TAG));
        self::assertSame('tenant', $scope->getArgument('$type'));
        self::assertSame(TimesheetScopeProviderInterface::class, (string) $scope->getArgument('$scopes'));
    }

    public function testABadScopeSubjectTypeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('entries.subjects.scope_subject.type must match');
        $this->load(['entries' => ['subjects' => ['scope_subject' => ['type' => 'Tenant']]]]);
    }

    public function testANonStringScopeSubjectTypeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['entries' => ['subjects' => ['scope_subject' => ['type' => 12]]]]);
    }

    public function testTheSearchRateIsAtLeastOne(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['entries' => ['subjects' => ['search' => ['max_per_minute' => 0]]]]);
    }

    /**
     * Loads the extension with the configuration.
     *
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
