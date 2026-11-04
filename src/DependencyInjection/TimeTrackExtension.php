<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection;

use LogicException;
use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\AlwaysActiveClientCredentialState;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientApiSettingsProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialStateInterface;
use Nowo\TimeTrackBundle\Client\ClientDistributionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientMinSupportedVersionProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\ConfigClientDistributionProvider;
use Nowo\TimeTrackBundle\Client\ConfigClientMinSupportedVersionProvider;
use Nowo\TimeTrackBundle\Client\DefaultClientAuthenticator;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsResolver;
use Nowo\TimeTrackBundle\Client\NullClientUserProfileProvider;
use Nowo\TimeTrackBundle\Client\OpaqueClientCredentialIssuer;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientDeviceController;
use Nowo\TimeTrackBundle\Controller\TimeTrackDeviceVerificationController;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener;
use Nowo\TimeTrackBundle\Form\TimerActionType;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CredentialClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DeviceAuthorizationRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmClientTokenRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmDeviceAuthorizationRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\AllowAllTimeTrackAccessChecker;
use Nowo\TimeTrackBundle\Security\ConfigurableTimeTrackAccessChecker;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Security\TaskProviderTaskAccessChecker;
use Nowo\TimeTrackBundle\Security\TimeTrackAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\TimeTrackBundle\Twig\TimeTrackTwigExtension;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

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

        if ($container->hasDefinition(TimeTrackTwigExtension::class)) {
            $container->getDefinition(TimeTrackTwigExtension::class)
                ->setArgument('$layoutTemplate', $config['templates']['layout'])
                ->setArgument('$cssFramework', $config['templates']['css_framework'])
                ->setArgument('$distributionProvider', new Reference(ClientDistributionProviderInterface::class));
        }
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
            ->setArgument('$minSupportedVersionProvider', new Reference(ClientMinSupportedVersionProviderInterface::class))
            ->setArgument('$taskPresenter', new Reference(ClientTaskPresenter::class))
            ->setArgument('$settingsProvider', new Reference(ClientApiSettingsProviderInterface::class))
            ->setArgument('$idleCheckResolver', new Reference(IdleCheckSettingsResolver::class));
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
