<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Deprecation;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeTrackPortsPass;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Doctrine\RecoveringFlusher;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackHostFlushProbe;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Dto\TaskReference;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\IdleResolution;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\IdleResolvedEvent;
use Nowo\TimeTrackBundle\Event\TaskEffortAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryAccessCheckEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryListQueryEvent;
use Nowo\TimeTrackBundle\Event\TimerStartEvent;
use Nowo\TimeTrackBundle\Event\TimerStopEvent;
use Nowo\TimeTrackBundle\Event\TimesheetAccessCheckEvent;
use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\BatchTaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Nowo\TimeTrackBundle\Port\Ports;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmClientTokenRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmDeviceAuthorizationRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Tests\Stub\DaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTeamContextProvider;
use Nowo\TimeTrackBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Tests\Support\Ports\RecordingTwinPorts;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Nowo\TransactionDoctrine\HostFlushProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_map;
use function array_values;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * 1.33.0 (REQ-ARCH-001 wave 0, REQ-BC-001): every default that flips in 2.0 and every member 2.0 removes triggers a
 * runtime notice when used; what keeps working in 2.0 (the opt-ins, the 1.23 twins, the bundle's own defaults) does not.
 */
final class TwoZeroNoticesTest extends TestCase
{
    /** The stores and the runner that flush the shared manager. */
    private const FLUSHERS = [
        TimeTrackTransactionRunner::class,
        DoctrineOrmTimeEntryRepository::class,
        DoctrineOrmActiveTimerRepository::class,
        DoctrineOrmClientTokenRepository::class,
        DoctrineOrmDeviceAuthorizationRepository::class,
        DoctrineOrmTimesheetRepository::class,
        DoctrineOrmBalanceLedgerRepository::class,
        DoctrineOrmCalendarRepository::class,
    ];

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function privateEntityManagerConfigs(): iterable
    {
        yield 'unset' => [[], true];
        yield 'false' => [['database' => ['private_entity_manager' => false]], true];
        yield 'true' => [['database' => ['private_entity_manager' => true]], false];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('privateEntityManagerConfigs')]
    public function testThePrivateEntityManagerNoticeAndTheProbeOnlyWithoutIt(array $config, bool $shared): void
    {
        $container = null;
        $notices   = $this->deprecations(static function () use ($config, &$container): void {
            $container = self::load($config);
        });
        self::assertInstanceOf(ContainerBuilder::class, $container);

        $mine = $this->matching($notices, 'private_entity_manager');
        if (!$shared) {
            self::assertSame([], $mine, 'explicitly true: no notice');
            self::assertFalse($container->hasDefinition(TimeTrackHostFlushProbe::class), 'the private manager never flushes the application\'s: no probe');
            foreach (self::FLUSHERS as $id) {
                self::assertArrayNotHasKey('$hostFlushProbe', $container->getDefinition($id)->getArguments(), $id);
            }

            return;
        }
        self::assertSame(['Since nowo-tech/time-track-bundle 1.33: ' . sprintf(TimeTrackExtension::PRIVATE_ENTITY_MANAGER_NOTICE, 'default')], $mine);
        self::assertStringContainsString('"nowo_time_track: { database: { private_entity_manager: true } }"', $mine[0]);
        $probe = $container->getDefinition(TimeTrackHostFlushProbe::class);
        self::assertEquals(new Reference(HostFlushProbe::SERVICE_ID, ContainerInterface::NULL_ON_INVALID_REFERENCE), $probe->getArgument('$probe'));
        self::assertSame(User::class, $probe->getArgument('$userClass'));
        self::assertSame([['event' => 'onFlush', 'method' => 'onFlush', 'lazy' => true, 'entity_manager' => 'default']], $probe->getTag('doctrine.event_listener'));
        self::assertSame([['method' => 'reset']], $probe->getTag('kernel.reset'));
        $boundaries = array_map(static fn (array $tag): mixed => $tag['event'], $probe->getTag('kernel.event_listener'));
        self::assertSame(['kernel.terminate', 'console.terminate', 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'], $boundaries);
        foreach (self::FLUSHERS as $id) {
            self::assertEquals(new Reference(TimeTrackHostFlushProbe::class), $container->getDefinition($id)->getArgument('$hostFlushProbe'), $id);
        }
    }

    public function testTheClientOriginNoticesOnlyWithTheClientApiOn(): void
    {
        self::assertSame([], $this->matching($this->deprecations(static fn (): ContainerBuilder => self::load(['clients' => ['cors_allowed_origins' => ['*']]])), 'origins'), 'client API off');

        $unset = $this->deprecations(static fn (): ContainerBuilder => self::load(['clients' => ['enabled' => true, 'cors_allowed_origins' => ['*']]]));
        self::assertSame(['Since nowo-tech/time-track-bundle 1.33: ' . TimeTrackExtension::CORS_WILDCARD_NOTICE], $this->matching($unset, 'cors_allowed_origins contains'));
        self::assertSame(['Since nowo-tech/time-track-bundle 1.33: ' . TimeTrackExtension::TAURI_WEBVIEW_ORIGINS_NOTICE], $this->matching($unset, 'allow_tauri_webview_origins is true'));

        $true = $this->deprecations(static fn (): ContainerBuilder => self::load(['clients' => ['enabled' => true], 'client_api' => ['allow_tauri_webview_origins' => true]]));
        self::assertCount(1, $this->matching($true, 'allow_tauri_webview_origins is true'));

        $migrated = $this->deprecations(static fn (): ContainerBuilder => self::load(['clients' => ['enabled' => true, 'cors_allowed_origins' => ['chrome-extension://abc']], 'client_api' => ['allow_tauri_webview_origins' => false]]));
        self::assertSame([], $this->matching($migrated, 'origins'));
    }

    public function testAHostServiceOnADeprecatedPortInterfaceIsReportedOnce(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.team', new Definition(StubTeamContextProvider::class));
        $container->setAlias(TeamContextProviderInterface::class, 'app.team');
        $container->setDefinition('app.tasks', new Definition(LegacyBatchTasks::class));
        $container->setAlias(TaskProviderInterface::class, 'app.tasks');

        $notices = $this->deprecations(static fn () => (new TimeTrackPortsPass())->process($container));

        self::assertCount(2, $notices);
        self::assertStringContainsString('The service "app.tasks" (' . LegacyBatchTasks::class . ') implements the deprecated ' . BatchTaskProviderInterface::class . ', which 2.0 removes: implement ' . BatchTaskDirectoryInterface::class . ' instead and keep it configured as nowo_time_track.task_provider', $notices[0]);
        self::assertStringContainsString('The service "app.team" (' . StubTeamContextProvider::class . ') implements the deprecated ' . TeamContextProviderInterface::class . ', which 2.0 removes: implement ' . TeamDirectoryInterface::class . ' instead and keep it configured as nowo_time_track.team_context_provider', $notices[1]);
    }

    public function testTheBundlesOwnDefaultsAndTwinOnlyServicesAreNotReported(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(StubTaskProvider::class, new Definition(StubTaskProvider::class));
        $container->setAlias(TaskProviderInterface::class, StubTaskProvider::class);
        $container->setDefinition('app.team', new Definition(RecordingTwinPorts::class));
        $container->setAlias(TeamContextProviderInterface::class, 'app.team');
        $container->setDefinition('app.profiles', new Definition(RecordingTwinPorts::class));
        $container->setAlias(ClientUserProfileProviderInterface::class, 'app.profiles');

        self::assertSame([], $this->deprecations(static fn () => (new TimeTrackPortsPass())->process($container)));
    }

    public function testADeprecatedProfileExtraIsReported(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.profiles', new Definition(DaysOffProfileProvider::class));
        $container->setAlias(ClientUserProfileProviderInterface::class, 'app.profiles');

        $notices = $this->deprecations(static fn () => (new TimeTrackPortsPass())->process($container));

        self::assertCount(2, $notices);
        self::assertStringContainsString('implements the deprecated ' . ClientUserDaysOffProviderInterface::class . ', which 2.0 removes: implement ' . ClientUserDaysOffSourceInterface::class, $notices[0]);
        self::assertStringContainsString('implements the deprecated ' . ClientUserProfileProviderInterface::class . ', which 2.0 removes: implement ' . ClientUserProfileSourceInterface::class, $notices[1]);
    }

    public function testAFactoryBuiltProfileProviderIsNotInspectedAtBuildTime(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.profiles', (new Definition(ClientUserProfileProviderInterface::class))->setFactory([DaysOffProfileFactory::class, 'create']));
        $container->setAlias(ClientUserProfileProviderInterface::class, 'app.profiles');

        self::assertSame([], $this->deprecations(static fn () => (new TimeTrackPortsPass())->process($container)));
    }

    public function testTheProbeInspectsTimeTrackRowUpdatesWithoutAUserClassAndNeverFailsTheFlush(): void
    {
        $user       = new TestUser('42', 'ana@example.com');
        $entry      = new TimeEntry($user, 'task-1', 'Task', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Web);
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([$entry]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $probe = new TimeTrackHostFlushProbe();

        $notices = $this->deprecations(static fn () => $probe->onFlush(new OnFlushEventArgs($entityManager)));
        self::assertCount(1, $notices);
        self::assertStringContainsString('Your application updated ' . TimeEntry::class . ' rows with its own entity manager', $notices[0]);

        $broken = $this->createStub(EntityManagerInterface::class);
        $broken->method('getUnitOfWork')->willThrowException(new RuntimeException('unexpected'));
        self::assertSame([], $this->deprecations(static fn () => $probe->onFlush(new OnFlushEventArgs($broken))));
    }

    public function testAServiceKnownOnlyAtRuntimeIsReportedWhenCreated(): void
    {
        $legacy  = new StubTeamContextProvider();
        $notices = $this->deprecations(static fn () => self::assertSame($legacy, Ports::port('team_context_provider', $legacy)));

        self::assertCount(1, $notices);
        self::assertStringContainsString('The service ' . StubTeamContextProvider::class . ' implements the deprecated ' . TeamContextProviderInterface::class, $notices[0]);
        self::assertSame([], $this->deprecations(static fn (): object => Ports::port('team_context_provider', new RecordingTwinPorts())));
    }

    public function testTheDeprecatedEventAndResultMembersAreReported(): void
    {
        $user  = new TestUser('42', 'ana@example.com', ['ROLE_USER']);
        $entry = new TimeEntry($user, 'task-1', 'Task', null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 3600, TimeEntrySource::Web);
        $now   = new DateTimeImmutable();
        $calls = [
            TimerStartEvent::class . '::getUser()'             => static fn (): UserInterface => (new TimerStartEvent($user, new TaskReference('task-1', 'Task'), ClientType::Web))->getUser(),
            TimerStopEvent::class . '::getUser()'              => static fn (): UserInterface => (new TimerStopEvent($user, $entry))->getUser(),
            IdleResolvedEvent::class . '::getUser()'           => static fn (): UserInterface => (new IdleResolvedEvent($user, IdleResolution::Keep, $now, $now, ClientType::Desktop))->getUser(),
            TimeEntryAccessCheckEvent::class . '::getUser()'   => static fn (): UserInterface => (new TimeEntryAccessCheckEvent($user, $entry, 'view', true))->getUser(),
            TaskEffortAccessCheckEvent::class . '::getUser()'  => static fn (): UserInterface => (new TaskEffortAccessCheckEvent($user, 'task-1'))->getUser(),
            TimesheetAccessCheckEvent::class . '::getViewer()' => static fn (): UserInterface => (new TimesheetAccessCheckEvent($user, '7', 'org-a', 'view', true))->getViewer(),
            TimeEntryListQueryEvent::class . '::getViewer()'   => static fn (): UserInterface => (new TimeEntryListQueryEvent($user, $now, $now))->getViewer(),
            ClientAuthResult::class . '::getUser()'            => static fn (): ?\Symfony\Component\Security\Core\User\UserInterface => ClientAuthResult::success($user)->getUser(),
        ];
        foreach ($calls as $member => $call) {
            $notices = $this->deprecations(static fn () => self::assertSame($user, $call()));
            self::assertCount(1, $notices, $member);
            self::assertStringStartsWith('Since nowo-tech/time-track-bundle 1.33: ' . $member . ' is deprecated since 1.23.0 and 2.0 removes it', $notices[0]);
        }

        self::assertSame([], $this->deprecations(static fn (): array => [
            (new TimerStartEvent($user, new TaskReference('task-1', 'Task'), ClientType::Web))->userRef(),
            (new TimesheetAccessCheckEvent($user, '7', 'org-a', 'view', true))->viewerRef(),
            ClientAuthResult::success($user)->userRef(),
            ClientAuthResult::success($user)->requireUser(),
        ]));
    }

    public function testResetIfClosedIsReportedAndTheFlushGoesThroughTheProbe(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $notices       = $this->deprecations(static fn () => RecoveringFlusher::resetIfClosed($entityManager));
        self::assertCount(1, $notices);
        self::assertStringContainsString('RecoveringFlusher::resetIfClosed() does nothing since 1.27.0 and 2.0 removes it', $notices[0]);

        $entityManager->expects(self::once())->method('flush');
        self::assertSame([], $this->deprecations(static fn () => RecoveringFlusher::flush($entityManager)));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([['user_class' => User::class] + $config], $container);

        return $container;
    }

    /**
     * @param list<string> $notices
     *
     * @return list<string>
     */
    private function matching(array $notices, string $needle): array
    {
        return array_values(array_filter($notices, static fn (string $message): bool => str_contains($message, $needle)));
    }

    /**
     * @return list<string> the deprecations triggered while `$work` ran
     */
    private function deprecations(callable $work): array
    {
        $seen = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in the finally block below)
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $work();
        } finally {
            restore_error_handler();
        }

        return $seen;
    }
}

/**
 * A host task provider still on the deprecated batch interface.
 *
 * @internal
 */
final class LegacyBatchTasks implements BatchTaskProviderInterface
{
    public function findForUser(string $taskId, UserInterface $user): ?TaskReference
    {
        return null;
    }

    public function listTrackableForUser(UserInterface $user, TaskListQuery $query): array
    {
        return [];
    }

    public function canUserTrack(UserInterface $user, string $taskId): bool
    {
        return false;
    }

    public function filterTrackable(UserInterface $user, array $taskIds): array
    {
        return [];
    }
}

/**
 * A factory-built profile provider (its class is unknown before compilation).
 *
 * @internal
 */
final class DaysOffProfileFactory
{
    public static function create(): DaysOffProfileProvider
    {
        return new DaysOffProfileProvider(null, null);
    }
}
