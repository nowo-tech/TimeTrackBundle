<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection;

use LogicException;
use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\DefaultClientAuthenticator;
use Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmActiveTimerRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmClientTokenRepository;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmTimeEntryRepository;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\AllowAllTimeTrackAccessChecker;
use Nowo\TimeTrackBundle\Security\ConfigurableTimeTrackAccessChecker;
use Nowo\TimeTrackBundle\Security\TimeTrackAccessCheckerInterface;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Twig\TimeTrackTwigExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;

final class TimeTrackExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $prefix            = rtrim((string) $config['table_prefix'], '_');
        $entriesTable      = $prefix . '_entries';
        $activeTimersTable = $prefix . '_active_timers';
        $clientTokensTable = $prefix . '_client_tokens';
        $emName            = (string) $config['database']['entity_manager'];
        $clients           = $config['clients'];
        $security          = $config['security'];

        $container->setParameter('nowo_time_track.user_class', $config['user_class']);
        $container->setParameter('nowo_time_track.entries_table', $entriesTable);
        $container->setParameter('nowo_time_track.active_timers_table', $activeTimersTable);
        $container->setParameter('nowo_time_track.client_tokens_table', $clientTokensTable);
        $container->setParameter('nowo_time_track.clients.enabled', (bool) $clients['enabled']);
        $container->setParameter('nowo_time_track.clients.token_ttl', (int) $clients['token_ttl']);
        $container->setParameter('nowo_time_track.clients.idle_threshold_seconds', (int) $clients['idle_threshold_seconds']);
        $container->setParameter('nowo_time_track.clients.routes', $clients['routes']);
        $container->setParameter('nowo_time_track.clients.cors_allowed_origins', $clients['cors_allowed_origins']);
        $container->setParameter('nowo_time_track.routes', $config['routes']);
        $container->setParameter('nowo_time_track.templates', $config['templates']);
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
            DoctrineOrmTimeEntryRepository::class   => TimeEntryRepositoryInterface::class,
            DoctrineOrmActiveTimerRepository::class => ActiveTimerRepositoryInterface::class,
            DoctrineOrmClientTokenRepository::class => ClientTokenRepositoryInterface::class,
        ] as $repoClass => $interface) {
            $container->setDefinition($repoClass, (new Definition($repoClass))
                ->setAutowired(false)
                ->setArgument('$entityManager', $emRef));
            $container->setAlias($interface, $repoClass);
        }

        $container->setDefinition(TimeTrackMetadataListener::class, (new Definition(TimeTrackMetadataListener::class))
            ->setArgument('$entriesTableName', $entriesTable)
            ->setArgument('$activeTimersTableName', $activeTimersTable)
            ->setArgument('$clientTokensTableName', $clientTokensTable)
            ->setArgument('$userClass', $config['user_class'])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $loginRateLimit = $clients['login_rate_limit'];
        $container->setDefinition(ClientLoginRateLimiter::class, (new Definition(ClientLoginRateLimiter::class))
            ->setAutowired(false)
            ->setArgument('$cache', new Reference((string) $loginRateLimit['cache_pool']))
            ->setArgument('$maxAttempts', (int) $loginRateLimit['max_attempts'])
            ->setArgument('$intervalSeconds', (int) $loginRateLimit['interval_seconds'])
            ->setArgument('$enabled', (bool) $loginRateLimit['enabled']));

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
            ->setArgument('$kernelEnvironment', '%kernel.environment%'));

        $container->setDefinition(TeamAccessGuard::class, (new Definition(TeamAccessGuard::class))
            ->setAutowired(false)
            ->setArgument('$teamContext', new Reference(TeamContextProviderInterface::class))
            ->setArgument('$eventDispatcher', new Reference('event_dispatcher'))
            ->setArgument('$adminRoles', $security['admin_roles'])
            ->setArgument('$managerCanViewEntries', (bool) $security['manager_can_view_entries'])
            ->setArgument('$managerCanEditEntries', (bool) $security['manager_can_edit_entries']));

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if ($container->hasDefinition(TimeTrackTwigExtension::class)) {
            $container->getDefinition(TimeTrackTwigExtension::class)
                ->setArgument('$layoutTemplate', $config['templates']['layout'])
                ->setArgument('$cssFramework', $config['templates']['css_framework']);
        }
    }

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
            if (!is_array($cfg)) {
                continue;
            }
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
