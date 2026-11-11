<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection;

use LogicException;
use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Calendar\CalendarEventMatcher;
use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthClientInterface;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthClients;
use Nowo\TimeTrackBundle\Calendar\CalendarPersonalDataExporter;
use Nowo\TimeTrackBundle\Calendar\CalendarReaderInterface;
use Nowo\TimeTrackBundle\Calendar\CalendarRefHasher;
use Nowo\TimeTrackBundle\Calendar\CalendarRuleService;
use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Calendar\CalendarSuggestionService;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncService;
use Nowo\TimeTrackBundle\Calendar\CalendarTokenEndpoint;
use Nowo\TimeTrackBundle\Calendar\CalendarWorkingHours;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarEventSource;
use Nowo\TimeTrackBundle\Calendar\GoogleCalendarOAuthClient;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarEventSource;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarOAuthClient;
use Nowo\TimeTrackBundle\Calendar\TaskKeyResolverInterface;
use Nowo\TimeTrackBundle\Client\AlwaysActiveClientCredentialState;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientApiSettingsProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialStateInterface;
use Nowo\TimeTrackBundle\Client\ClientDistributionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientIdempotencyStore;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientMinSupportedVersionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingTimeContext;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\ConfigClientDistributionProvider;
use Nowo\TimeTrackBundle\Client\ConfigClientMinSupportedVersionProvider;
use Nowo\TimeTrackBundle\Client\DefaultClientAuthenticator;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsResolver;
use Nowo\TimeTrackBundle\Client\NullClientUserProfileProvider;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Command\BackfillEntryScopeCommand;
use Nowo\TimeTrackBundle\Command\CalendarSyncCommand;
use Nowo\TimeTrackBundle\Command\TimesheetsAutoLockCommand;
use Nowo\TimeTrackBundle\Command\TimesheetsPurgeCommand;
use Nowo\TimeTrackBundle\Controller\TimeTrackCalendarApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientDeviceController;
use Nowo\TimeTrackBundle\Controller\TimeTrackDeviceVerificationController;
use Nowo\TimeTrackBundle\Controller\TimeTrackMeetingsApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackWorkingTimeApiController;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Doctrine\FormerPersonSnapshotListener;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Form\TimerActionType;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingContext;
use Nowo\TimeTrackBundle\Meeting\MeetingLinkDetector;
use Nowo\TimeTrackBundle\Meeting\MeetingQueryParser;
use Nowo\TimeTrackBundle\Meeting\MeetingResponder;
use Nowo\TimeTrackBundle\Meeting\MeetingSettings;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarRuleRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarSuggestionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DeviceAuthorizationRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmBalanceLedgerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmCalendarRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmClientTokenRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmDeviceAuthorizationRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetEventRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimesheetRepository;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryResolver;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetEventRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRetentionRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Retention\TimeEntryRetentionPolicy;
use Nowo\TimeTrackBundle\Security\AllowAllTimeTrackAccessChecker;
use Nowo\TimeTrackBundle\Security\ConfigurableTimeTrackAccessChecker;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Security\TimeTrackAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\TaskMove\TaskTimeMover;
use Nowo\TimeTrackBundle\Timesheet\AuditChainVerifier;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\SingleScopeProvider;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAuditTrail;
use Nowo\TimeTrackBundle\Timesheet\TimesheetFormHandler;
use Nowo\TimeTrackBundle\Timesheet\TimesheetPersonalDataExporter;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetSnapshotBuilder;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\TimeTrackBundle\Twig\TimeTrackTwigExtension;
use Nowo\TimeTrackBundle\Ui\TimesheetViewBuilder;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Nowo\TimeTrackBundle\WorkingTime\ConfigOvertimePolicy;
use Nowo\TimeTrackBundle\WorkingTime\DerivedWorkingCalendar;
use Nowo\TimeTrackBundle\WorkingTime\OvertimePolicyInterface;
use Nowo\TimeTrackBundle\WorkingTime\TaskTimeWorkedTimeSource;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeSourceInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_diff_key;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * Loads the bundle services and parameters and prepends the Doctrine mapping, UiKit and FormKit defaults.
 */
final class TimeTrackExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Registers the parameters, repositories, access checker, client services and Twig globals from the configuration.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $prefix            = rtrim((string) $config['table_prefix'], '_');
        $entriesTable      = $prefix . '_entries';
        $activeTimersTable = $prefix . '_active_timers';
        $clientTokensTable = $prefix . '_client_tokens';
        $deviceCodesTable  = $prefix . '_device_codes';
        $timesheetsTable   = $prefix . '_timesheets';
        $emName            = (string) $config['database']['entity_manager'];
        $clients           = $config['clients'];
        $security          = $config['security'];

        $container->setParameter('nowo_time_track.user_class', $config['user_class']);
        $container->setParameter('nowo_time_track.entries_table', $entriesTable);
        $container->setParameter('nowo_time_track.active_timers_table', $activeTimersTable);
        $container->setParameter('nowo_time_track.client_tokens_table', $clientTokensTable);
        $container->setParameter('nowo_time_track.device_codes_table', $deviceCodesTable);
        $container->setParameter('nowo_time_track.clients.enabled', (bool) $clients['enabled']);
        $container->setParameter('nowo_time_track.clients.token_ttl', (int) $clients['token_ttl']);
        $container->setParameter('nowo_time_track.clients.idle_threshold_seconds', (int) $clients['idle_threshold_seconds']);
        $container->setParameter('nowo_time_track.clients.routes', $clients['routes']);
        $container->setParameter('nowo_time_track.clients.cors_allowed_origins', $clients['cors_allowed_origins']);
        $container->setParameter('nowo_time_track.routes', $config['routes']);
        // 1.x shape (layout, css_framework, index, reports); index / reports are the resolved web_ui.templates values.
        $container->setParameter('nowo_time_track.templates', array_merge($config['templates'], $config['web_ui']['templates']));
        $container->setParameter('nowo_time_track.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_time_track.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_time_track.reports.page_size', (int) $config['reports']['page_size']);
        $container->setParameter('nowo_time_track.templates.layout', $config['templates']['layout']);
        $container->setParameter('nowo_time_track.templates.css_framework', $config['templates']['css_framework']);
        $container->setParameter('nowo_time_track.route_prefix', $config['route_prefix']);
        $container->setParameter('nowo_time_track.security.admin_roles', $security['admin_roles']);
        $container->setParameter('nowo_time_track.security.access_roles', $security['access_roles']);
        $container->setParameter('nowo_time_track.security.access_checker', $security['access_checker']);
        $container->setParameter('nowo_time_track.security.allow_unauthenticated', $security['allow_unauthenticated']);

        if (
            !$security['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('TimeTrackBundle manage UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $this->registerAccessChecker($container, $security);

        $taskProviderId = $config['task_provider'] ?? StubTaskProvider::class;
        if (!is_string($taskProviderId) || $taskProviderId === '') {
            $taskProviderId = StubTaskProvider::class;
        }
        if ($taskProviderId === StubTaskProvider::class && !$container->hasDefinition(StubTaskProvider::class)) {
            $container->setDefinition(StubTaskProvider::class, new Definition(StubTaskProvider::class));
        }
        $container->setAlias(TaskProviderInterface::class, $taskProviderId);

        $teamProviderId = $config['team_context_provider'] ?? NullTeamContextProvider::class;
        if (!is_string($teamProviderId) || $teamProviderId === '') {
            $teamProviderId = NullTeamContextProvider::class;
        }
        if ($teamProviderId === NullTeamContextProvider::class && !$container->hasDefinition(NullTeamContextProvider::class)) {
            $container->setDefinition(NullTeamContextProvider::class, new Definition(NullTeamContextProvider::class));
        }
        $container->setAlias(TeamContextProviderInterface::class, $teamProviderId);

        $emRef = new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName));

        foreach ([
            DoctrineOrmTimeEntryRepository::class           => TimeEntryRepositoryInterface::class,
            DoctrineOrmActiveTimerRepository::class         => ActiveTimerRepositoryInterface::class,
            DoctrineOrmClientTokenRepository::class         => ClientTokenRepositoryInterface::class,
            DoctrineOrmDeviceAuthorizationRepository::class => DeviceAuthorizationRepositoryInterface::class,
        ] as $repoClass => $interface) {
            $repoDefinition = (new Definition($repoClass))
                ->setAutowired(false)
                ->setArgument('$entityManager', $emRef)
                ->setArgument('$managerRegistry', new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE));
            if ($repoClass === DoctrineOrmTimeEntryRepository::class) {
                // Since the delta GG-D-2 fix: warns when a user row cannot be locked.
                $repoDefinition->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE));
                $repoDefinition->addTag('monolog.logger', ['channel' => 'time_track']);
            }
            if (in_array($repoClass, [DoctrineOrmClientTokenRepository::class, DoctrineOrmDeviceAuthorizationRepository::class], true)) {
                $repoDefinition->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));
            }
            $container->setDefinition($repoClass, $repoDefinition);
            $container->setAlias($interface, $repoClass);
        }
        // Bulk revocation by credential: a separate alias so host decorators of ClientTokenRepositoryInterface keep working.
        $container->setAlias(CredentialClientTokenRepositoryInterface::class, DoctrineOrmClientTokenRepository::class);

        $container->setDefinition(TimeTrackMetadataListener::class, (new Definition(TimeTrackMetadataListener::class))
            ->setArgument('$entriesTableName', $entriesTable)
            ->setArgument('$activeTimersTableName', $activeTimersTable)
            ->setArgument('$clientTokensTableName', $clientTokensTable)
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$deviceCodesTableName', $deviceCodesTable)
            ->setArgument('$timesheetsTableName', $timesheetsTable)
            ->setArgument('$timesheetEventsTableName', $prefix . '_timesheet_events')
            ->setArgument('$balanceLedgerTableName', $prefix . '_balance_ledger')
            ->setArgument('$calendarConnectionsTableName', $prefix . '_calendar_connections')
            ->setArgument('$calendarSuggestionsTableName', $prefix . '_calendar_suggestions')
            ->setArgument('$calendarRulesTableName', $prefix . '_calendar_rules')
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $loginRateLimit = $clients['login_rate_limit'];
        $container->setDefinition(ClientLoginRateLimiter::class, (new Definition(ClientLoginRateLimiter::class))
            ->setAutowired(false)
            ->setArgument('$cache', new Reference((string) $loginRateLimit['cache_pool']))
            ->setArgument('$maxAttempts', (int) $loginRateLimit['max_attempts'])
            ->setArgument('$intervalSeconds', (int) $loginRateLimit['interval_seconds'])
            ->setArgument('$enabled', (bool) $loginRateLimit['enabled'])
            ->setArgument('$maxAttemptsPerIp', $loginRateLimit['max_attempts_per_ip'] !== null ? (int) $loginRateLimit['max_attempts_per_ip'] : null)
            ->setArgument('$maxAttemptsPerUsername', $loginRateLimit['max_attempts_per_username'] !== null ? (int) $loginRateLimit['max_attempts_per_username'] : null));

        $authenticatorId = $clients['authenticator'] ?? null;
        if (!is_string($authenticatorId) || $authenticatorId === '') {
            $authenticatorId  = DefaultClientAuthenticator::class;
            $authenticatorDef = (new Definition(DefaultClientAuthenticator::class))
                ->setAutowired(false)
                ->setArgument('$passwordHasher', new Reference('security.user_password_hasher'));
            $userProviderId = $clients['user_provider'] ?? null;
            if (is_string($userProviderId) && $userProviderId !== '') {
                $authenticatorDef->setArgument('$userProvider', new Reference($userProviderId));
            } else {
                $authenticatorDef->setAutowired(true);
            }
            $container->setDefinition(DefaultClientAuthenticator::class, $authenticatorDef);
        }
        $container->setAlias(ClientAuthenticatorInterface::class, $authenticatorId);

        $container->setDefinition(ClientResponseFactory::class, (new Definition(ClientResponseFactory::class))
            ->setAutowired(false)
            ->setArgument('$allowedOrigins', $clients['cors_allowed_origins'])
            ->setArgument('$kernelEnvironment', '%kernel.environment%')
            ->setArgument('$allowAnyExtensionOrigin', (bool) $clients['cors_allow_any_extension_origin'])
            ->setArgument('$settingsProvider', new Reference(ClientApiSettingsProviderInterface::class)));

        $container->setDefinition(TeamAccessGuard::class, (new Definition(TeamAccessGuard::class))
            ->setAutowired(false)
            ->setArgument('$teamContext', new Reference(TeamContextProviderInterface::class))
            ->setArgument('$eventDispatcher', new Reference('event_dispatcher'))
            ->setArgument('$adminRoles', $security['admin_roles'])
            ->setArgument('$managerCanViewEntries', (bool) $security['manager_can_view_entries'])
            ->setArgument('$managerCanEditEntries', (bool) $security['manager_can_edit_entries'])
            ->setArgument('$managerRegistry', new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE)));

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $this->registerClientApi($container, $config);
        $this->registerImports($container, $config);
        $this->registerTimesheets($container, $config, $emRef);
        /** @var list<class-string> $acceptedUserClasses */
        $acceptedUserClasses = $config['accepted_user_classes'];
        $this->registerUserReferences($container, (string) $config['user_class'], $emRef, $acceptedUserClasses);

        if ($container->hasDefinition(TimeTrackTwigExtension::class)) {
            $container->getDefinition(TimeTrackTwigExtension::class)
                ->setArgument('$layoutTemplate', $config['templates']['layout'])
                ->setArgument('$cssFramework', $config['templates']['css_framework'])
                ->setArgument('$distributionProvider', new Reference(ClientDistributionProviderInterface::class));
        }
    }

    /**
     * Since 1.18: the {@see UserReferenceResolverInterface} port (default {@see DoctrineUserReferenceResolver}), injected
     * into every service that stores the security user in a `user` association. Hosts override the alias.
     *
     * @param list<class-string> $acceptedUserClasses Since 1.19: `accepted_user_classes` (JV-T3)
     */
    private function registerUserReferences(ContainerBuilder $container, string $userClass, Reference $emRef, array $acceptedUserClasses): void
    {
        $container->setDefinition(DoctrineUserReferenceResolver::class, (new Definition(DoctrineUserReferenceResolver::class))
            ->setAutowired(false)
            ->setArgument('$entityManager', $emRef)
            ->setArgument('$userClass', $userClass)
            ->setArgument('$acceptedUserClasses', $acceptedUserClasses));
        $container->setAlias(UserReferenceResolverInterface::class, DoctrineUserReferenceResolver::class);

        $reference = new Reference(UserReferenceResolverInterface::class);
        foreach ([
            TimerService::class,
            ManualEntryService::class,
            ImportedTimeEntryWriter::class,
            TimesheetService::class,
            BalanceLedger::class,
            ClientAuthService::class,
            DeviceAuthorizationService::class,
            CalendarConnectionService::class,
            CalendarRuleService::class,
        ] as $serviceId) {
            if ($container->hasDefinition($serviceId)) {
                $container->getDefinition($serviceId)->setArgument('$userReferences', $reference);
            }
        }
    }

    /**
     * Since 1.11: the imported-entry writer, its repository alias and the retention policy (after the resource scan, so
     * these explicit definitions win).
     *
     * @param array<string, mixed> $config Processed configuration
     */
    private function registerImports(ContainerBuilder $container, array $config): void
    {
        /** @var array{max_entry_seconds: int} $imports */
        $imports = $config['imports'];
        /** @var array{include_imported: bool, anonymise_on_user_delete: bool, former_person_listener: bool} $retention */
        $retention = $config['retention'];

        $container->setParameter('nowo_time_track.imports.max_entry_seconds', $imports['max_entry_seconds']);
        $container->setParameter('nowo_time_track.retention.include_imported', $retention['include_imported']);
        // Since 1.12 (FJ-L3): follows TimeEntryRepositoryInterface (host replacement or decorator), never the raw store.
        $container->setDefinition(ImportedTimeEntryRepositoryInterface::class, (new Definition(ImportedTimeEntryRepositoryInterface::class))
            ->setFactory([ImportedTimeEntryRepositoryResolver::class, 'resolve'])
            ->setArguments([new Reference(TimeEntryRepositoryInterface::class)]));
        $container->setDefinition(ImportedTimeEntryWriter::class, (new Definition(ImportedTimeEntryWriter::class))
            ->setArguments([
                '$repository'      => new Reference(TimeEntryRepositoryInterface::class),
                '$eventDispatcher' => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$maxEntrySeconds' => $imports['max_entry_seconds'],
                '$logger'          => new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                '$clock'           => new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $container->setDefinition(TimeEntryRetentionPolicy::class, (new Definition(TimeEntryRetentionPolicy::class))
            ->setArgument('$includeImported', $retention['include_imported']));
        // Since 1.17 (Beacon spec 217 FR-008): the time of tasks moved to another board follows them; public, no route.
        $container->setDefinition(TaskTimeMover::class, (new Definition(TaskTimeMover::class))
            ->setPublic(true)
            ->setArguments([
                '$entries'         => new Reference(TimeEntryRepositoryInterface::class),
                '$timers'          => new Reference(ActiveTimerRepositoryInterface::class),
                '$periodLockGuard' => new Reference(PeriodLockGuard::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$eventDispatcher' => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$logger'          => new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        // Since 1.15: release, anonymise and export of a person's entries; public for host erasers and offboarding.
        $container->setDefinition(FormerPersonTimeEntries::class, (new Definition(FormerPersonTimeEntries::class))
            ->setPublic(true)
            ->setArguments([
                '$repository'            => new Reference(TimeEntryRepositoryInterface::class),
                '$profiles'              => new Reference(ClientUserProfileProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$clock'                 => new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$logger'                => new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                '$timers'                => new Reference(TimerService::class),
                '$anonymiseOnUserDelete' => $retention['anonymise_on_user_delete'],
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        if (!$retention['former_person_listener']) {
            // Since 1.19 (REQ-ARCH-001): no listener on the host entity manager; the host calls FormerPersonTimeEntries.
            return;
        }
        $container->setDefinition(FormerPersonSnapshotListener::class, (new Definition(FormerPersonSnapshotListener::class))
            ->setArgument('$entries', new Reference(FormerPersonTimeEntries::class))
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$timers', new Reference(TimerService::class))
            ->addTag('doctrine.event_listener', ['event' => 'preRemove'])
            ->addTag('doctrine.event_listener', ['event' => 'onFlush'])
            // Since 1.16 (HA-L18): first, so a host's postFlush listener that flushes cannot cancel the settling.
            ->addTag('doctrine.event_listener', ['event' => 'postFlush', 'priority' => 1000])
            ->addTag('kernel.reset', ['method' => 'reset'])
            // Since 1.19 (REQ-RUNTIME-002): cleared by the bundle itself at each boundary, never by kernel.reset alone.
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onBoundary', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onBoundary', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'method' => 'onBoundary', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'method' => 'onBoundary', 'priority' => -1024]));
    }

    /**
     * Since 1.13: timesheet stores (always mapped), the tenant scope provider, the period lock guard and its wiring
     * into every bundle write path (timer service, import writer, team access guard), the scope back-fill command.
     *
     * @param array<string, mixed> $config Processed configuration
     */
    private function registerTimesheets(ContainerBuilder $container, array $config, Reference $emRef): void
    {
        /** @var array{enabled: bool, scope_mode: string, scope_provider: string|null, approver_provider: string|null, period: string, default_timezone: string, submit_requires_period_end: bool, auto_lock_after_days: int|null, allow_self_approval: bool, admin_roles_view: bool, reopen_roles: list<string>, approver_sees_notes: bool, audit_blocked_edits: bool, retention_years: int} $timesheets */
        $timesheets  = $config['timesheets'];
        $enabled     = $timesheets['enabled'];
        $registryRef = new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->setParameter('nowo_time_track.timesheets', $timesheets);
        $container->setParameter('nowo_time_track.timesheets.enabled', $enabled);

        $container->setDefinition(DoctrineOrmTimesheetRepository::class, (new Definition(DoctrineOrmTimesheetRepository::class))
            ->setArguments(['$entityManager' => $emRef, '$managerRegistry' => $registryRef]));
        $container->setAlias(TimesheetRepositoryInterface::class, DoctrineOrmTimesheetRepository::class);
        $container->setAlias(TimesheetRetentionRepositoryInterface::class, DoctrineOrmTimesheetRepository::class);
        $container->setDefinition(DoctrineOrmTimesheetEventRepository::class, (new Definition(DoctrineOrmTimesheetEventRepository::class))
            ->setArguments(['$entityManager' => $emRef]));
        $container->setAlias(TimesheetEventRepositoryInterface::class, DoctrineOrmTimesheetEventRepository::class);
        $container->setDefinition(DoctrineOrmBalanceLedgerRepository::class, (new Definition(DoctrineOrmBalanceLedgerRepository::class))
            ->setArguments(['$entityManager' => $emRef, '$managerRegistry' => $registryRef]));
        $container->setAlias(BalanceLedgerRepositoryInterface::class, DoctrineOrmBalanceLedgerRepository::class);

        /** @var array{enabled: bool} $workingTime */
        $workingTime = $config['working_time'];
        /** @var array{enabled: bool} $calendar */
        $calendar = $config['calendar'];
        // Since 1.14: calendar connections live in a tenant scope too ('' without a scope provider).
        if ($enabled || $workingTime['enabled'] || $calendar['enabled']) {
            // Working time without timesheets and without a scope provider: one scope '' (like scope_mode single).
            $providerId = $timesheets['scope_provider'];
            if ($timesheets['scope_mode'] === 'single' || !is_string($providerId) || $providerId === '') {
                $container->setDefinition(SingleScopeProvider::class, new Definition(SingleScopeProvider::class));
                $container->setAlias(TimesheetScopeProviderInterface::class, SingleScopeProvider::class);
            } else {
                $container->setAlias(TimesheetScopeProviderInterface::class, $providerId);
            }
        }
        $scopeRef = new Reference(TimesheetScopeProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $container->setDefinition(PeriodLockGuard::class, (new Definition(PeriodLockGuard::class))
            ->setPublic(true)
            ->setArguments([
                '$timesheets'      => new Reference(TimesheetRepositoryInterface::class),
                '$enabled'         => $enabled,
                '$eventDispatcher' => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]));
        $guardRef = new Reference(PeriodLockGuard::class);

        $container->getDefinition(TimerService::class)
            ->setArgument('$periodLockGuard', $guardRef)
            ->setArgument('$scopeProvider', $scopeRef);
        $container->getDefinition(ImportedTimeEntryWriter::class)
            ->setArgument('$periodLockGuard', $guardRef)
            ->setArgument('$scopeProvider', $scopeRef);
        $container->getDefinition(TeamAccessGuard::class)
            ->setArgument('$periodLockGuard', $guardRef);
        $container->getDefinition(BackfillEntryScopeCommand::class)
            ->setArgument('$scopeProvider', $scopeRef)
            ->setArgument('$lockGuard', $guardRef);

        $this->registerWorkingTime($container, $config, $scopeRef, $guardRef);
        $this->registerTimesheetWorkflow($container, $config, $scopeRef);
        $this->registerCalendar($container, $config, $emRef, $scopeRef);
    }

    /**
     * Since 1.14: calendar suggestions (contract §6) — the store (always mapped), the settings, the host resolver and
     * credential provider, the Google / Microsoft adapters (only with an HTTP client), the matcher, the sync, connection
     * and rule services and the sync command.
     *
     * @param array<string, mixed> $config Processed configuration
     */
    private function registerCalendar(ContainerBuilder $container, array $config, Reference $emRef, Reference $scopeRef): void
    {
        /** @var array{enabled: bool, credential_provider: string|null, task_key_resolver: string|null, providers: list<string>, modes: list<string>, lookback_days: int, suggestion_ttl_days: int, sync_interval_minutes: int, max_event_seconds: int, max_rules_per_user: int, max_pages: int} $calendar */
        $calendar    = $config['calendar'];
        $enabled     = $calendar['enabled'];
        $clockRef    = new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $loggerRef   = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $dispatchRef = new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        // Since 1.19: the OAuth apps (secrets) stay out of the parameter bag.
        $container->setParameter('nowo_time_track.calendar', array_diff_key($calendar, ['oauth' => true]));
        $container->setParameter('nowo_time_track.calendar.enabled', $enabled);

        $container->setDefinition(DoctrineOrmCalendarRepository::class, (new Definition(DoctrineOrmCalendarRepository::class))
            ->setArguments(['$entityManager' => $emRef, '$managerRegistry' => new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE)]));
        foreach ([CalendarConnectionRepositoryInterface::class, CalendarSuggestionRepositoryInterface::class, CalendarRuleRepositoryInterface::class] as $interface) {
            $container->setAlias($interface, DoctrineOrmCalendarRepository::class);
        }
        $container->setDefinition(CalendarSettings::class, (new Definition(CalendarSettings::class))
            ->setArguments([
                '$enabled'             => $enabled,
                '$providers'           => $calendar['providers'],
                '$modes'               => $calendar['modes'],
                '$lookbackDays'        => $calendar['lookback_days'],
                '$suggestionTtlDays'   => $calendar['suggestion_ttl_days'],
                '$syncIntervalMinutes' => $calendar['sync_interval_minutes'],
                '$maxEventSeconds'     => $calendar['max_event_seconds'],
                '$maxRulesPerUser'     => $calendar['max_rules_per_user'],
            ]));
        $resolverId   = $calendar['task_key_resolver'];
        $credentialId = $calendar['credential_provider'];
        $resolverRef  = $enabled && is_string($resolverId) && $resolverId !== '' ? new Reference($resolverId) : null;
        $credentials  = $enabled && is_string($credentialId) && $credentialId !== '' ? new Reference($credentialId) : null;
        if ($resolverRef instanceof Reference) {
            $container->setAlias(TaskKeyResolverInterface::class, (string) $resolverRef);
        }
        $container->setDefinition(CalendarRefHasher::class, (new Definition(CalendarRefHasher::class))
            ->setArguments(['$secret' => '%kernel.secret%']));
        $container->setDefinition(CalendarWorkingHours::class, (new Definition(CalendarWorkingHours::class))
            ->setArguments(['$profileProvider' => new Reference(ClientUserProfileProviderInterface::class), '$logger' => $loggerRef])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $matcher = null;
        if ($resolverRef instanceof Reference) {
            $container->setDefinition(CalendarEventMatcher::class, (new Definition(CalendarEventMatcher::class))
                ->setArguments([
                    '$resolver'   => $resolverRef,
                    '$taskAccess' => new Reference(TaskAccessCheckerInterface::class),
                    '$hasher'     => new Reference(CalendarRefHasher::class),
                    '$settings'   => new Reference(CalendarSettings::class),
                ]));
            $matcher = new Reference(CalendarEventMatcher::class);
        }
        $this->registerCalendarReaders($container, $calendar, $clockRef, $loggerRef);
        $sources = [];
        // The adapters need symfony/http-client (a `suggest`): without the framework `http_client` a sync fails with
        // the code `no_http_client`.
        if ($enabled && $credentials instanceof Reference) {
            $sources = [new Reference(GoogleCalendarEventSource::class), new Reference(MicrosoftCalendarEventSource::class)];
        }
        $container->setDefinition(CalendarConnectionService::class, (new Definition(CalendarConnectionService::class))
            ->setPublic(true)
            ->setArguments([
                '$connections'     => new Reference(CalendarConnectionRepositoryInterface::class),
                '$settings'        => new Reference(CalendarSettings::class),
                '$scopeProvider'   => $resolverRef instanceof Reference ? $scopeRef : null,
                '$credentials'     => $credentials,
                '$eventDispatcher' => $dispatchRef,
                '$clock'           => $clockRef,
            ]));
        $container->setDefinition(CalendarRuleService::class, (new Definition(CalendarRuleService::class))
            ->setPublic(true)
            ->setArguments([
                '$rules'       => new Reference(CalendarRuleRepositoryInterface::class),
                '$connections' => new Reference(CalendarConnectionService::class),
                '$taskAccess'  => new Reference(TaskAccessCheckerInterface::class),
                '$settings'    => new Reference(CalendarSettings::class),
                '$clock'       => $clockRef,
            ]));
        $container->setDefinition(CalendarSyncService::class, (new Definition(CalendarSyncService::class))
            ->setPublic(true)
            ->setArguments([
                '$connections'     => new Reference(CalendarConnectionRepositoryInterface::class),
                '$suggestions'     => new Reference(CalendarSuggestionRepositoryInterface::class),
                '$rules'           => new Reference(CalendarRuleRepositoryInterface::class),
                '$settings'        => new Reference(CalendarSettings::class),
                '$workingHours'    => new Reference(CalendarWorkingHours::class),
                '$matcher'         => $matcher,
                '$sources'         => new IteratorArgument($sources),
                '$credentials'     => $credentials,
                '$eventDispatcher' => $dispatchRef,
                '$clock'           => $clockRef,
                '$logger'          => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        // Since 1.14 (contract §12.4): personal-data export of the calendar tables.
        $container->setDefinition(CalendarPersonalDataExporter::class, (new Definition(CalendarPersonalDataExporter::class))
            ->setPublic(true)
            ->setArguments([
                '$connections' => new Reference(CalendarConnectionRepositoryInterface::class),
                '$rules'       => new Reference(CalendarRuleRepositoryInterface::class),
                '$suggestions' => new Reference(CalendarSuggestionRepositoryInterface::class),
            ]));
        $container->setDefinition(CalendarSyncCommand::class, (new Definition(CalendarSyncCommand::class))
            ->setArguments(['$sync' => new Reference(CalendarSyncService::class)])
            ->addTag('console.command'));

        // Since 1.14: entries without a timer (POST entries, accepted suggestions) and the calendar client API.
        /** @var array{enabled: bool, max_age_days: int} $manual */
        $manual = $config['manual_entries'];
        $container->setParameter('nowo_time_track.manual_entries', $manual);
        $container->setDefinition(ManualEntryService::class, (new Definition(ManualEntryService::class))
            ->setPublic(true)
            ->setArguments([
                '$timerService'    => new Reference(TimerService::class),
                '$entries'         => new Reference(TimeEntryRepositoryInterface::class),
                '$timers'          => new Reference(ActiveTimerRepositoryInterface::class),
                '$taskAccess'      => new Reference(TaskAccessCheckerInterface::class),
                '$eventDispatcher' => $dispatchRef,
                '$enabled'         => $manual['enabled'],
                '$maxAgeDays'      => $manual['max_age_days'],
                '$periodLockGuard' => new Reference(PeriodLockGuard::class),
                '$scopeProvider'   => $scopeRef,
                '$clock'           => $clockRef,
                '$logger'          => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $container->setDefinition(CalendarSuggestionService::class, (new Definition(CalendarSuggestionService::class))
            ->setPublic(true)
            ->setArguments([
                '$suggestions'     => new Reference(CalendarSuggestionRepositoryInterface::class),
                '$entries'         => new Reference(ManualEntryService::class),
                '$taskAccess'      => new Reference(TaskAccessCheckerInterface::class),
                '$settings'        => new Reference(CalendarSettings::class),
                '$resolver'        => $resolverRef,
                '$eventDispatcher' => $dispatchRef,
                '$logger'          => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $container->getDefinition(TimeTrackCalendarApiController::class)
            ->setArgument('$clock', $clockRef);
        $container->getDefinition(TimeTrackClientApiController::class)
            ->setArgument('$calendar', new Reference(CalendarConnectionService::class))
            ->setArgument('$manualEntries', new Reference(ManualEntryService::class));

        $this->registerMeetings($container, $config, $clockRef, $loggerRef);
    }

    /**
     * Since 1.19 (Beacon request 2026-11-09): the calendar readers ({@see CalendarReaderInterface}) and the OAuth helpers
     * ({@see CalendarOAuthClientInterface}), registered whether or not calendar suggestions are on, so a host meeting
     * sync can inject them. Since 1.20 also {@see CalendarOAuthClients} (provider discovery) and the runtime app provider
     * (`calendar.oauth.app_provider`). They need symfony/http-client (a `suggest`); without it they fail with `no_http_client`.
     *
     * @param array<string, mixed> $calendar Processed `calendar` configuration
     */
    private function registerCalendarReaders(ContainerBuilder $container, array $calendar, Reference $clockRef, Reference $loggerRef): void
    {
        /** @var array{max_pages: int, microsoft_scope: string, http_client: string, oauth: array{app_provider: string|null, google: array{client_id: string, client_secret: string}, microsoft: array{client_id: string, client_secret: string, tenant: string}}} $calendar */
        // Since 1.20 (audit ML-L1): `calendar.http_client`; the framework client is optional (null without
        // symfony/http-client), a host's own client id must exist.
        $httpRef = $calendar['http_client'] === 'http_client'
            ? new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Reference($calendar['http_client']);
        $container->setDefinition(CalendarHttpClient::class, (new Definition(CalendarHttpClient::class))
            ->setArguments(['$httpClient' => $httpRef, '$clock' => $clockRef]));
        $container->setDefinition(GoogleCalendarEventSource::class, (new Definition(GoogleCalendarEventSource::class))
            ->setArguments(['$http' => new Reference(CalendarHttpClient::class), '$maxPages' => $calendar['max_pages']]));
        $container->setDefinition(MicrosoftCalendarEventSource::class, (new Definition(MicrosoftCalendarEventSource::class))
            ->setArguments(['$http' => new Reference(CalendarHttpClient::class), '$maxPages' => $calendar['max_pages'], '$scope' => $calendar['microsoft_scope']]));
        $container->setDefinition(CalendarTokenEndpoint::class, (new Definition(CalendarTokenEndpoint::class))
            ->setArguments(['$httpClient' => $httpRef, '$clock' => $clockRef]));
        // Since 1.20: with an app provider the apps are read at runtime on every call; the static secrets are then not
        // compiled into the container at all.
        $appProvider = $calendar['oauth']['app_provider'];
        $appRef      = is_string($appProvider) ? new Reference($appProvider) : null;
        $google      = $calendar['oauth']['google'];
        $container->setDefinition(GoogleCalendarOAuthClient::class, (new Definition(GoogleCalendarOAuthClient::class))
            ->setArguments([
                '$endpoint'     => new Reference(CalendarTokenEndpoint::class),
                '$clientId'     => $appRef instanceof Reference ? '' : $google['client_id'],
                '$clientSecret' => $appRef instanceof Reference ? '' : $google['client_secret'],
                '$appProvider'  => $appRef,
                '$logger'       => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $microsoft = $calendar['oauth']['microsoft'];
        $container->setDefinition(MicrosoftCalendarOAuthClient::class, (new Definition(MicrosoftCalendarOAuthClient::class))
            ->setArguments([
                '$endpoint'     => new Reference(CalendarTokenEndpoint::class),
                '$clientId'     => $appRef instanceof Reference ? '' : $microsoft['client_id'],
                '$clientSecret' => $appRef instanceof Reference ? '' : $microsoft['client_secret'],
                '$tenant'       => $microsoft['tenant'],
                '$scope'        => $calendar['microsoft_scope'],
                '$appProvider'  => $appRef,
                '$logger'       => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $container->setDefinition(CalendarOAuthClients::class, (new Definition(CalendarOAuthClients::class))
            ->setArguments([
                '$google'    => new Reference(GoogleCalendarOAuthClient::class),
                '$microsoft' => new Reference(MicrosoftCalendarOAuthClient::class),
            ]));
    }

    /**
     * Since 1.14 (contract §22): the meetings API — settings, link detector, query parser, responder, the `GET me`
     * context and the controller. The host provider is required when enabled (checked by the configuration).
     *
     * @param array<string, mixed> $config Processed configuration
     */
    private function registerMeetings(ContainerBuilder $container, array $config, Reference $clockRef, Reference $loggerRef): void
    {
        /** @var array{enabled: bool, provider: string|null, max_range_days: int, max_past_days: int, default_limit: int, refresh_after_seconds: int, rate_limit: array{per_hour: int, per_minute: int}, jitsi_hosts: list<string>, links_calendars_route: string|null} $meetings */
        $meetings = $config['meetings'];
        $provider = $meetings['provider'];
        $container->setParameter('nowo_time_track.meetings', $meetings);
        $container->setDefinition(MeetingSettings::class, (new Definition(MeetingSettings::class))
            ->setArguments([
                '$enabled'             => $meetings['enabled'],
                '$maxRangeDays'        => $meetings['max_range_days'],
                '$maxPastDays'         => $meetings['max_past_days'],
                '$defaultLimit'        => $meetings['default_limit'],
                '$refreshAfterSeconds' => $meetings['refresh_after_seconds'],
                '$perHour'             => $meetings['rate_limit']['per_hour'],
                '$perMinute'           => $meetings['rate_limit']['per_minute'],
                '$calendarsRoute'      => $meetings['links_calendars_route'],
            ]));
        $container->setDefinition(MeetingLinkDetector::class, (new Definition(MeetingLinkDetector::class))
            ->setArguments(['$jitsiHosts' => $meetings['jitsi_hosts']]));
        $container->setDefinition(MeetingQueryParser::class, (new Definition(MeetingQueryParser::class))
            ->setArguments(['$settings' => new Reference(MeetingSettings::class)]));
        $container->setDefinition(MeetingResponder::class, (new Definition(MeetingResponder::class))
            ->setArguments(['$links' => new Reference(MeetingLinkDetector::class), '$taskAccess' => new Reference(TaskAccessCheckerInterface::class)]));
        $container->setDefinition(MeetingContext::class, (new Definition(MeetingContext::class))
            ->setArguments([
                '$settings'     => new Reference(MeetingSettings::class),
                '$provider'     => $meetings['enabled'] && is_string($provider) && $provider !== '' ? new Reference($provider) : null,
                '$urlGenerator' => new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$logger'       => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $container->getDefinition(TimeTrackMeetingsApiController::class)
            ->setArguments([
                '$guard'           => new Reference(ClientApiGuard::class),
                '$responseFactory' => new Reference(ClientResponseFactory::class),
                '$rateLimiter'     => new Reference(ClientApiRateLimiter::class),
                '$profileProvider' => new Reference(ClientUserProfileProviderInterface::class),
                '$context'         => new Reference(MeetingContext::class),
                '$parser'          => new Reference(MeetingQueryParser::class),
                '$responder'       => new Reference(MeetingResponder::class),
                '$settings'        => new Reference(MeetingSettings::class),
                '$clock'           => $clockRef,
                '$logger'          => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']);
        $container->getDefinition(TimeTrackClientApiController::class)
            ->setArgument('$meetings', new Reference(MeetingContext::class));
    }

    /**
     * Since 1.13: audit trail, snapshot builder, the timesheet state machine, the chain verifier and the commands.
     *
     * @param array<string, mixed> $config Processed configuration
     */
    private function registerTimesheetWorkflow(ContainerBuilder $container, array $config, Reference $scopeRef): void
    {
        /** @var array{enabled: bool, period: string, submit_requires_period_end: bool, auto_lock_after_days: int|null, audit_blocked_edits: bool, retention_years: int, approver_sees_notes: bool} $timesheets */
        $timesheets = $config['timesheets'];
        /** @var array{enabled: bool, overtime: array{ratify_on_approval: bool}} $workingTime */
        $workingTime = $config['working_time'];
        $clockRef    = new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $loggerRef   = new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);

        $container->setDefinition(TimesheetAuditTrail::class, (new Definition(TimesheetAuditTrail::class))
            ->setArguments([
                '$events'     => new Reference(TimesheetEventRepositoryInterface::class),
                '$timesheets' => new Reference(TimesheetRepositoryInterface::class),
                '$clock'      => $clockRef,
            ]));
        if ($timesheets['audit_blocked_edits']) {
            $container->getDefinition(PeriodLockGuard::class)->setArgument('$blockedEditRecorder', new Reference(TimesheetAuditTrail::class));
        }
        $container->setDefinition(TimesheetSnapshotBuilder::class, (new Definition(TimesheetSnapshotBuilder::class))
            ->setArguments([
                '$entries' => new Reference(TimeEntryRepositoryInterface::class),
                '$balance' => new Reference(WorkingTimeBalanceService::class),
            ]));
        $container->setDefinition(TimesheetService::class, (new Definition(TimesheetService::class))
            ->setPublic(true)
            ->setArguments([
                '$timesheets'              => new Reference(TimesheetRepositoryInterface::class),
                '$events'                  => new Reference(TimesheetEventRepositoryInterface::class),
                '$auditTrail'              => new Reference(TimesheetAuditTrail::class),
                '$accessGuard'             => new Reference(TimesheetAccessGuard::class),
                '$snapshots'               => new Reference(TimesheetSnapshotBuilder::class),
                '$timezoneResolver'        => new Reference(TimesheetTimezoneResolver::class),
                '$activeTimers'            => new Reference(ActiveTimerRepositoryInterface::class),
                '$ledger'                  => $workingTime['enabled'] ? new Reference(BalanceLedger::class) : null,
                '$eventDispatcher'         => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$clock'                   => $clockRef,
                '$enabled'                 => $timesheets['enabled'],
                '$periodKind'              => TimesheetPeriodKind::from($timesheets['period']),
                '$submitRequiresPeriodEnd' => $timesheets['submit_requires_period_end'],
                '$ratifyOnApproval'        => $workingTime['overtime']['ratify_on_approval'],
                '$logger'                  => $loggerRef,
                '$scopeProvider'           => $scopeRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $container->setDefinition(AuditChainVerifier::class, (new Definition(AuditChainVerifier::class))
            ->setArguments([
                '$timesheets' => new Reference(TimesheetRepositoryInterface::class),
                '$retention'  => new Reference(TimesheetRetentionRepositoryInterface::class),
                '$events'     => new Reference(TimesheetEventRepositoryInterface::class),
                '$ledger'     => new Reference(BalanceLedgerRepositoryInterface::class),
            ]));
        // Since the GB fixes: personal-data export of the working-time tables (contract §12.4).
        $container->setDefinition(TimesheetPersonalDataExporter::class, (new Definition(TimesheetPersonalDataExporter::class))
            ->setPublic(true)
            ->setArguments([
                '$timesheets' => new Reference(TimesheetRepositoryInterface::class),
                '$retention'  => new Reference(TimesheetRetentionRepositoryInterface::class),
                '$events'     => new Reference(TimesheetEventRepositoryInterface::class),
                '$ledger'     => new Reference(BalanceLedgerRepositoryInterface::class),
            ]));
        $container->getDefinition(TimesheetsAutoLockCommand::class)
            ->setArgument('$afterDays', $timesheets['auto_lock_after_days'])
            ->setArgument('$clock', $clockRef)
            ->setArgument('$logger', $loggerRef);
        // Since 1.13: client API working-time routes.
        /** @var array{workday_provider: string|null, links_working_time_route: string|null, rate_limit: array{cache_pool: string}} $api */
        $api     = $config['client_api'];
        $workday = $api['workday_provider'];
        $container->setDefinition(ClientIdempotencyStore::class, (new Definition(ClientIdempotencyStore::class))
            ->setArguments(['$cache' => new Reference($api['rate_limit']['cache_pool'])]));
        $container->setDefinition(ClientWorkingTimeContext::class, (new Definition(ClientWorkingTimeContext::class))
            ->setArguments([
                '$timesheets'       => new Reference(TimesheetService::class),
                '$balance'          => new Reference(WorkingTimeBalanceService::class),
                '$accessGuard'      => new Reference(TimesheetAccessGuard::class),
                '$lockGuard'        => new Reference(PeriodLockGuard::class),
                '$workdayProvider'  => is_string($workday) && $workday !== '' ? new Reference($workday) : null,
                '$timerService'     => new Reference(TimerService::class),
                '$translator'       => new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$profileProvider'  => new Reference(ClientUserProfileProviderInterface::class),
                '$urlGenerator'     => new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$workingTimeRoute' => $api['links_working_time_route'],
            ]));
        $container->getDefinition(TimeTrackWorkingTimeApiController::class)
            ->setArgument('$clock', $clockRef);
        $container->getDefinition(TimeTrackClientApiController::class)
            ->setArgument('$workingTime', new Reference(ClientWorkingTimeContext::class));
        $container->getDefinition(ClientTaskPresenter::class)
            ->setArgument('$effort', new Reference(TimeEntryRepositoryInterface::class))
            ->setArgument('$effortAccess', new Reference(TeamAccessGuard::class));

        // Since 1.13: the web side of the components (contract §16a).
        $container->setDefinition(TimesheetFormHandler::class, (new Definition(TimesheetFormHandler::class))
            ->setPublic(true)
            ->setArguments([
                '$forms'      => new Reference('form.factory'),
                '$timesheets' => new Reference(TimesheetService::class),
                '$ledger'     => $workingTime['enabled'] ? new Reference(BalanceLedger::class) : null,
                // GB-L2: the handler checks the CSRF token itself (fails closed without a token manager).
                '$csrfTokens' => new Reference('security.csrf.token_manager', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]));
        $container->setDefinition(TimesheetViewBuilder::class, (new Definition(TimesheetViewBuilder::class))
            ->setPublic(true)
            ->setArguments([
                '$timesheets'        => new Reference(TimesheetService::class),
                '$forms'             => new Reference(TimesheetFormHandler::class),
                '$taskAccess'        => new Reference(TaskAccessCheckerInterface::class),
                '$approverSeesNotes' => $timesheets['approver_sees_notes'],
                '$timerService'      => new Reference(TimerService::class),
            ]));

        $container->getDefinition(TimesheetsPurgeCommand::class)
            ->setArgument('$retentionYears', $timesheets['retention_years'])
            ->setArgument('$clock', $clockRef)
            ->setArgument('$logger', $loggerRef);
    }

    /**
     * Since 1.13: working calendar, worked-time source, overtime policy (host services or the defaults), the zone
     * resolver, the balance service, the timesheet access guard and the balance ledger.
     *
     * @param array<string, mixed> $config Processed configuration
     */
    private function registerWorkingTime(ContainerBuilder $container, array $config, Reference $scopeRef, Reference $guardRef): void
    {
        /** @var array{enabled: bool, calendar_provider: string|null, worked_time_source: string|null, overtime_policy: string|null, balance: array{start_date: string|null}, overtime: array{daily_mode: string, ordinary_max_seconds: int, weekly_mode: string, weekly_max_seconds: int, annual_cap_seconds: int, ratify_on_approval: bool}} $workingTime */
        $workingTime = $config['working_time'];
        /** @var array{approver_provider: string|null, default_timezone: string, admin_roles_view: bool, reopen_roles: list<string>} $timesheets */
        $timesheets = $config['timesheets'];
        /** @var array{admin_roles: list<string>} $security */
        $security  = $config['security'];
        $loggerRef = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->setParameter('nowo_time_track.working_time', $workingTime);
        $container->setParameter('nowo_time_track.working_time.enabled', $workingTime['enabled']);

        $container->setDefinition(TimesheetTimezoneResolver::class, (new Definition(TimesheetTimezoneResolver::class))
            ->setArguments([
                '$scopeProvider'   => $scopeRef,
                '$profileProvider' => new Reference(ClientUserProfileProviderInterface::class),
                '$defaultTimezone' => $timesheets['default_timezone'],
            ]));
        $container->setDefinition(DerivedWorkingCalendar::class, (new Definition(DerivedWorkingCalendar::class))
            ->setArguments(['$profileProvider' => new Reference(ClientUserProfileProviderInterface::class), '$logger' => $loggerRef])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $container->setDefinition(TaskTimeWorkedTimeSource::class, (new Definition(TaskTimeWorkedTimeSource::class))
            ->setArguments(['$entries' => new Reference(TimeEntryRepositoryInterface::class), '$timezoneResolver' => new Reference(TimesheetTimezoneResolver::class)]));
        $overtime = $workingTime['overtime'];
        $container->setDefinition(ConfigOvertimePolicy::class, (new Definition(ConfigOvertimePolicy::class))
            ->setArguments([
                '$dailyMode'          => $overtime['daily_mode'],
                '$ordinaryMaxSeconds' => $overtime['ordinary_max_seconds'],
                '$weeklyMode'         => $overtime['weekly_mode'],
                '$weeklyMaxSeconds'   => $overtime['weekly_max_seconds'],
                '$annualCapSeconds'   => $overtime['annual_cap_seconds'],
            ]));
        foreach ([
            WorkingCalendarProviderInterface::class => [$workingTime['calendar_provider'], DerivedWorkingCalendar::class],
            WorkedTimeSourceInterface::class        => [$workingTime['worked_time_source'], TaskTimeWorkedTimeSource::class],
            OvertimePolicyInterface::class          => [$workingTime['overtime_policy'], ConfigOvertimePolicy::class],
        ] as $interface => [$serviceId, $default]) {
            $container->setAlias($interface, is_string($serviceId) && $serviceId !== '' ? $serviceId : $default);
        }

        $clockRef = new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->setDefinition(WorkingTimeBalanceService::class, (new Definition(WorkingTimeBalanceService::class))
            ->setPublic(true)
            ->setArguments([
                '$calendar'         => new Reference(WorkingCalendarProviderInterface::class),
                '$workedSource'     => new Reference(WorkedTimeSourceInterface::class),
                '$policy'           => new Reference(OvertimePolicyInterface::class),
                '$ledger'           => new Reference(BalanceLedgerRepositoryInterface::class),
                '$timesheets'       => new Reference(TimesheetRepositoryInterface::class),
                '$timezoneResolver' => new Reference(TimesheetTimezoneResolver::class),
                '$clock'            => $clockRef,
                '$balanceStartDate' => $workingTime['balance']['start_date'],
                '$enabled'          => $workingTime['enabled'],
                '$logger'           => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));

        $approverId = $timesheets['approver_provider'];
        $container->setDefinition(TimesheetAccessGuard::class, (new Definition(TimesheetAccessGuard::class))
            ->setPublic(true)
            ->setArguments([
                '$scopeProvider'    => new Reference(TimesheetScopeProviderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$approverProvider' => is_string($approverId) && $approverId !== '' ? new Reference($approverId) : null,
                '$eventDispatcher'  => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$adminRoles'       => $security['admin_roles'],
                '$adminRolesView'   => $timesheets['admin_roles_view'],
                '$reopenRoles'      => $timesheets['reopen_roles'],
                '$managerRegistry'  => new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]));
        $container->setDefinition(BalanceLedger::class, (new Definition(BalanceLedger::class))
            ->setPublic(true)
            ->setArguments([
                '$lines'           => new Reference(BalanceLedgerRepositoryInterface::class),
                '$accessGuard'     => new Reference(TimesheetAccessGuard::class),
                '$lockGuard'       => $guardRef,
                '$eventDispatcher' => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$clock'           => $clockRef,
                '$logger'          => new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
    }

    /**
     * Client API 1.9 services (contract v0.2): host interfaces with config defaults, settings / distribution providers,
     * rate limiter, auditor, device flow. Registered after the resource scan so these definitions win.
     *
     * @param array<string, mixed> $config Processed configuration
     */
    private function registerClientApi(ContainerBuilder $container, array $config): void
    {
        /** @var array{allowed_origins: list<string>, allow_tauri_webview_origins: bool, allow_metadata: bool, settings_provider: string|null, credential_state: string|null, credential_issuer: string|null, task_access_checker: string|null, profile_provider: string|null, backdate: array{max_seconds: int, clock_skew_seconds: int}, idle: array{allow_reassign: bool}, idle_check: array{enabled: bool, threshold_minutes: int, answer_timeout_minutes: int, user_can_disable: bool, provider: string|null}, task_url: array{origin: string|null}, rate_limit: array{cache_pool: string, per_token: int, per_ip: int, device_per_ip: int, window_seconds: int}, audit: array{denial_interval_seconds: int}, device_flow: array{enabled: bool, default_page: bool, verification_uri: string|null, code_ttl: int, interval: int, lookup_limit: int, lookup_window_seconds: int, max_pending_per_prefix: int, max_pending: int}} $api */
        $api = $config['client_api'];
        /** @var array{provider: string|null, extension: array<string, string|null>, desktop: array<string, string|null>, min_supported_version: array{provider: string|null, extension: string|null, desktop: string|null, web: string|null}} $distribution */
        $distribution = $config['client_distribution'];
        /** @var array{cors_allowed_origins: list<string>} $clients */
        $clients = $config['clients'];
        /** @var array{allow_unauthenticated: bool} $security */
        $security = $config['security'];
        /** @var array{device_verify: array{path: string, name: string}} $routes */
        $routes    = $config['routes'];
        $device    = $api['device_flow'];
        $rateLimit = $api['rate_limit'];
        $clockRef  = new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $loggerRef = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $container->setParameter('nowo_time_track.client_api.allow_metadata', $api['allow_metadata']);
        $container->setParameter('nowo_time_track.client_api.device_flow.enabled', $device['enabled']);
        $container->setParameter('nowo_time_track.client_api.device_flow.default_page', $device['enabled'] && $device['default_page']);

        $container->setDefinition(ConfigClientApiSettingsProvider::class, (new Definition(ConfigClientApiSettingsProvider::class))
            ->setArguments([
                '$allowedOrigins'                => array_values(array_unique([...$clients['cors_allowed_origins'], ...$api['allowed_origins']])),
                '$perTokenLimit'                 => $rateLimit['per_token'],
                '$perIpLimit'                    => $rateLimit['per_ip'],
                '$deviceIpLimit'                 => $rateLimit['device_per_ip'],
                '$windowSeconds'                 => $rateLimit['window_seconds'],
                '$allowTauriWebviewOrigins'      => $api['allow_tauri_webview_origins'],
                '$backdateMaxSeconds'            => $api['backdate']['max_seconds'],
                '$clockSkewSeconds'              => $api['backdate']['clock_skew_seconds'],
                '$allowIdleReassign'             => $api['idle']['allow_reassign'],
                '$idleCheckEnabled'              => $api['idle_check']['enabled'],
                '$idleCheckThresholdMinutes'     => $api['idle_check']['threshold_minutes'],
                '$idleCheckAnswerTimeoutMinutes' => $api['idle_check']['answer_timeout_minutes'],
                '$idleCheckUserCanDisable'       => $api['idle_check']['user_can_disable'],
            ]));
        $container->setDefinition(ConfigClientDistributionProvider::class, (new Definition(ConfigClientDistributionProvider::class))
            ->setArguments(['$extension' => $distribution['extension'], '$desktop' => $distribution['desktop']]));
        $minVersion = $distribution['min_supported_version'];
        $container->setDefinition(ConfigClientMinSupportedVersionProvider::class, (new Definition(ConfigClientMinSupportedVersionProvider::class))
            ->setArguments(['$versions' => ['extension' => $minVersion['extension'], 'desktop' => $minVersion['desktop'], 'web' => $minVersion['web']]]));

        foreach ([
            ClientApiSettingsProviderInterface::class         => [$api['settings_provider'], ConfigClientApiSettingsProvider::class],
            ClientDistributionProviderInterface::class        => [$distribution['provider'], ConfigClientDistributionProvider::class],
            ClientMinSupportedVersionProviderInterface::class => [$minVersion['provider'], ConfigClientMinSupportedVersionProvider::class],
            ClientCredentialStateInterface::class             => [$api['credential_state'], AlwaysActiveClientCredentialState::class],
            ClientCredentialIssuerInterface::class            => [$api['credential_issuer'], OpaqueClientCredentialIssuer::class],
            TaskAccessCheckerInterface::class                 => [$api['task_access_checker'], TaskProviderTaskAccessChecker::class],
            ClientUserProfileProviderInterface::class         => [$api['profile_provider'], NullClientUserProfileProvider::class],
        ] as $interface => [$serviceId, $default]) {
            $container->setAlias($interface, is_string($serviceId) && $serviceId !== '' ? $serviceId : $default);
        }

        $container->setDefinition(ClientApiRateLimiter::class, (new Definition(ClientApiRateLimiter::class))
            ->setArguments(['$cache' => new Reference($rateLimit['cache_pool']), '$clock' => $clockRef]));

        $container->setDefinition(ClientApiAuditor::class, (new Definition(ClientApiAuditor::class))
            ->setArguments([
                '$eventDispatcher'       => new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '$rateLimiter'           => new Reference(ClientApiRateLimiter::class),
                '$denialIntervalSeconds' => $api['audit']['denial_interval_seconds'],
                '$clock'                 => $clockRef,
                '$logger'                => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));

        $container->setDefinition(DeviceAuthorizationService::class, (new Definition(DeviceAuthorizationService::class))
            ->setAutowired(true)
            ->setPublic(true)
            ->setArguments([
                '$codeTtlSeconds'      => $device['code_ttl'],
                '$intervalSeconds'     => $device['interval'],
                '$lookupLimit'         => $device['lookup_limit'],
                '$lookupWindowSeconds' => $device['lookup_window_seconds'],
                '$maxPendingPerPrefix' => $device['max_pending_per_prefix'],
                '$maxPending'          => $device['max_pending'],
                '$clock'               => $clockRef,
            ]));

        $container->setDefinition(ClientTaskPresenter::class, (new Definition(ClientTaskPresenter::class))
            ->setArguments([
                '$timerService'  => new Reference(TimerService::class),
                '$taskUrlOrigin' => $api['task_url']['origin'],
                '$logger'        => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));
        $idleProvider = $api['idle_check']['provider'];
        $container->setDefinition(IdleCheckSettingsResolver::class, (new Definition(IdleCheckSettingsResolver::class))
            ->setArguments([
                '$settingsProvider' => new Reference(ClientApiSettingsProviderInterface::class),
                '$provider'         => is_string($idleProvider) && $idleProvider !== '' ? new Reference($idleProvider) : null,
                '$logger'           => $loggerRef,
            ])
            ->addTag('monolog.logger', ['channel' => 'time_track']));

        $container->getDefinition(TimeTrackClientApiController::class)
            ->setArgument('$allowMetadata', $api['allow_metadata'])
            ->setArgument('$enabledLocales', '%kernel.enabled_locales%')
            ->setArgument('$minSupportedVersionProvider', new Reference(ClientMinSupportedVersionProviderInterface::class))
            ->setArgument('$taskPresenter', new Reference(ClientTaskPresenter::class))
            ->setArgument('$settingsProvider', new Reference(ClientApiSettingsProviderInterface::class))
            ->setArgument('$idleCheckResolver', new Reference(IdleCheckSettingsResolver::class))
            ->setArgument('$translator', new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $container->getDefinition(TimeTrackClientDeviceController::class)
            ->setArgument('$verificationUri', $device['verification_uri'])
            ->setArgument('$verificationRoute', $device['default_page'] ? $routes['device_verify']['name'] : null);
        $container->getDefinition(TimeTrackDeviceVerificationController::class)
            ->setArgument('$allowUnauthenticated', $security['allow_unauthenticated']);
    }

    /**
     * Configuration alias (`nowo_time_track`).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** @param array<string, mixed> $security */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        if ($security['allow_unauthenticated']) {
            $accessCheckerId = 'nowo_time_track.access_checker.allow_all';
            $container->setDefinition($accessCheckerId, new Definition(AllowAllTimeTrackAccessChecker::class));
            $container->setAlias(TimeTrackAccessCheckerInterface::class, $accessCheckerId);

            return;
        }

        $accessCheckerId = $security['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_time_track.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableTimeTrackAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$accessRoles', $security['access_roles']));
        }

        $container->setAlias(TimeTrackAccessCheckerInterface::class, $accessCheckerId);
    }

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }

    /**
     * Prepends the Doctrine mapping, the UiKit stack defaults and the `time_track` FormKit profile.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TimeTrackBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }

        $this->prependUiKitDefaults($container);
        $this->prependFormKitDefaults($container);
    }

    /**
     * Registers the `time_track` FormKit profile of the manage UI forms (REQ-TWIG-005 rule 11) unless the host defines
     * `nowo_form_kit.profiles.time_track` itself. The profile adds no classes and no convention label / placeholder /
     * help keys: the forms render exactly their explicit options until a host themes the profile.
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $configs = $container->getExtensionConfig('nowo_form_kit');
        foreach ($configs as $cfg) {
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(TimerActionType::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $seed = ['profiles' => [TimerActionType::FORM_KIT_PROFILE => self::formKitProfile()]];
        if ($configs === []) {
            // No FormKit config in the host: keep FormKit's implicit `default` profile as the default one (a config
            // that only lists this profile would otherwise make it the default of every host form).
            $seed['default_profile']     = 'default';
            $seed['profiles']['default'] = ['alias' => 'default'];
        }

        $container->prependExtensionConfig('nowo_form_kit', $seed);
    }

    /**
     * Default `nowo_form_kit.profiles.time_track` (REQ-UI-003 "Form theme" row).
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => TimerActionType::FORM_KIT_PROFILE,
            'translation_domain' => TimeTrackBundle::TRANSLATION_DOMAIN,
            'auto_placeholder'   => false,
            'auto_help'          => false,
        ];
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from templates
     * so kit macros resolve the same stack. Does not override keys the host already set.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config    = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
        $defaults  = [];

        if (!$hostHasCssFramework) {
            $fw                        = (string) ($templates['css_framework'] ?? 'tabler');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $fwForIcons           = (string) ($defaults['css_framework'] ?? $templates['css_framework'] ?? 'tabler');
            $defaults['icon_set'] = $fwForIcons === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }
}
