<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection;

use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Configuration tree for TimeTrackBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_time_track';

    /** @var list<string> Host CSS stacks accepted by templates.css_framework (REQ-UI-001). */
    public const CSS_FRAMEWORKS = [
        'bootstrap',
        'bootstrap4',
        'bootstrap5',
        'tailwind',
        'foundation',
        'custom',
        'tabler',
        'none',
    ];

    /** 1.x default of templates.layout (deprecated shim, maps to the UiKit admin layout). */
    public const LEGACY_LAYOUT = '@NowoTimeTrackBundle/layout.html.twig';

    public const DEFAULT_INDEX_TEMPLATE = '@NowoTimeTrackBundle/time_track/index.html.twig';

    public const DEFAULT_REPORTS_TEMPLATE = '@NowoTimeTrackBundle/time_track/reports.html.twig';

    /**
     * Builds the `nowo_time_track` configuration tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->beforeNormalization()
                ->ifTrue(static fn (mixed $v): bool => is_array($v) && is_array($v['templates'] ?? null))
                ->then($this->mapDeprecatedTemplates(...))
            ->end()
            ->children()
                ->scalarNode('user_class')
                    ->info('FQCN of the application user entity (must implement UserInterface and expose getId()).')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->example('App\\Entity\\User')
                ->end()
                ->scalarNode('table_prefix')
                    ->info('Prefix for time track tables (entries, active_timers, client_tokens).')
                    ->defaultValue('time_track_')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('task_provider')
                    ->info('Service id implementing TaskProviderInterface. Defaults to StubTaskProvider for demos.')
                    ->defaultValue(StubTaskProvider::class)
                ->end()
                ->scalarNode('team_context_provider')
                    ->info('Service id implementing TeamContextProviderInterface.')
                    ->defaultValue(NullTeamContextProvider::class)
                ->end()
                ->scalarNode('route_prefix')
                    ->defaultValue('')
                ->end()
                ->arrayNode('database')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('entity_manager')
                            ->defaultValue('default')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('access_checker')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('admin_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_USER'])
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('manager_can_view_entries')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('manager_can_edit_entries')
                            ->info('Deprecated: only read by TeamAccessGuard::canEditEntry() for host code; the bundle ships no entry edit route.')
                            ->defaultTrue()
                            ->setDeprecated('nowo-tech/time-track-bundle', '1.5', 'The "%path%.%node%" option is deprecated: the bundle ships no time-entry edit route. Decide edit access in a "nowo_time_track.time_entry.access_check" listener (TimeEntryAccessCheckEvent) instead.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('routes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('index')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/time-track')->end()
                                ->scalarNode('name')->defaultValue('nowo_time_track_index')->end()
                            ->end()
                        ->end()
                        ->arrayNode('reports')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/time-track/reports')->end()
                                ->scalarNode('name')->defaultValue('nowo_time_track_reports')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('reports')
                    ->info('Reports page of the manage UI (REQ-PERF-001).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('page_size')
                            ->info('Entries per reports page (query parameter `page`).')
                            ->min(1)
                            ->max(500)
                            ->defaultValue(50)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->info('Manage UI pages on the UiKit page contract (REQ-UI-003).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Layout of the pages (passed to the UiKit scaffolds as ListPage::$layout). null = nowo_ui_kit.layouts.admin. A 1.x layout without the UiKit canonical blocks is wrapped in a deprecated bridge.')
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template override: a host template that extends @!NowoTimeTrackBundle/<template> and overrides a data block.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('index')
                                    ->defaultValue(self::DEFAULT_INDEX_TEMPLATE)
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('reports')
                                    ->defaultValue(self::DEFAULT_REPORTS_TEMPLATE)
                                    ->cannotBeEmpty()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->info('Deprecated since 1.8 (removed in 2.0): use web_ui.layout_template, web_ui.templates.* and nowo_ui_kit.css_framework.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout')
                            ->defaultValue(self::LEGACY_LAYOUT)
                            ->cannotBeEmpty()
                            ->info('Deprecated: use web_ui.layout_template. Still exposed as Twig global nowo_time_track_layout for 1.x template overrides.')
                            ->setDeprecated('nowo-tech/time-track-bundle', '1.8', 'The "%path%.%node%" option is deprecated: use "nowo_time_track.web_ui.layout_template" (null = nowo_ui_kit.layouts.admin).')
                        ->end()
                        ->enumNode('css_framework')
                            ->values(self::CSS_FRAMEWORKS)
                            ->defaultValue('tabler')
                            ->info('Deprecated: set nowo_ui_kit.css_framework. Until 2.0 the value still seeds nowo_ui_kit.css_framework / icon_set when the host does not set them, and stays the Twig global nowo_time_track_css_framework.')
                            ->setDeprecated('nowo-tech/time-track-bundle', '1.8', 'The "%path%.%node%" option is deprecated: set "nowo_ui_kit.css_framework" (the pages render through the UiKit theme slots).')
                        ->end()
                        ->scalarNode('index')
                            ->defaultValue(self::DEFAULT_INDEX_TEMPLATE)
                            ->setDeprecated('nowo-tech/time-track-bundle', '1.8', 'The "%path%.%node%" option is deprecated: use "nowo_time_track.web_ui.templates.index".')
                        ->end()
                        ->scalarNode('reports')
                            ->defaultValue(self::DEFAULT_REPORTS_TEMPLATE)
                            ->setDeprecated('nowo-tech/time-track-bundle', '1.8', 'The "%path%.%node%" option is deprecated: use "nowo_time_track.web_ui.templates.reports".')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('clients')
                    ->info('Browser extension and Tauri desktop agent API (Bearer token auth).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('token_ttl')
                            ->info('Bearer token lifetime in seconds.')
                            ->defaultValue(86_400)
                            ->min(60)
                        ->end()
                        ->integerNode('idle_threshold_seconds')
                            ->info('Desktop idle detection threshold in seconds.')
                            ->defaultValue(300)
                            ->min(60)
                        ->end()
                        ->scalarNode('authenticator')
                            ->info('Service id implementing ClientAuthenticatorInterface.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('user_provider')
                            ->info('Symfony user provider for default password authenticator.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('cors_allowed_origins')
                            ->info('Exact allowed CORS origins, e.g. chrome-extension://<extension-id> (tauri:// is always allowed; "*" only outside prod).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('cors_allow_any_extension_origin')
                            ->info('Opt-in: allow every chrome-extension:// and moz-extension:// origin (any installed extension). Prefer listing your extension id in cors_allowed_origins.')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('login_rate_limit')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('cache_pool')
                                    ->defaultValue('cache.app')
                                ->end()
                                ->integerNode('max_attempts')
                                    ->defaultValue(10)
                                    ->min(1)
                                ->end()
                                ->integerNode('interval_seconds')
                                    ->defaultValue(900)
                                    ->min(60)
                                ->end()
                                ->integerNode('max_attempts_per_ip')
                                    ->info('Failed logins per client IP (any username) within interval_seconds. null = 5 × max_attempts.')
                                    ->defaultNull()
                                    ->min(1)
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('routes')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('login')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/login')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_login')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('logout')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/logout')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_logout')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('me')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/me')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_me')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('tasks')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/tasks')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_tasks')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('timer')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/timer')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timer')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('timer_start')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/timer/start')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timer_start')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('timer_stop')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/timer/stop')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timer_stop')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('heartbeat')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/heartbeat')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_heartbeat')->end()
                                    ->end()
                                ->end()
                                ->arrayNode('entries')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('path')->defaultValue('/api/time-track/entries')->end()
                                        ->scalarNode('name')->defaultValue('nowo_time_track_api_entries')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * 1.8: the deprecated `templates.layout|index|reports` keys feed `web_ui.*` unless `web_ui` sets them (both stay
     * readable; the 1.x default layout is not mapped, so it resolves to the UiKit admin layout).
     *
     * @param array<string, mixed> $config Raw configuration
     *
     * @return array<string, mixed>
     */
    private function mapDeprecatedTemplates(array $config): array
    {
        $templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
        $webUi     = is_array($config['web_ui'] ?? null) ? $config['web_ui'] : [];
        $pages     = is_array($webUi['templates'] ?? null) ? $webUi['templates'] : [];

        $layout = $templates['layout'] ?? null;
        if (is_string($layout) && $layout !== self::LEGACY_LAYOUT && !array_key_exists('layout_template', $webUi)) {
            $webUi['layout_template'] = $layout;
        }
        foreach (['index', 'reports'] as $page) {
            $template = $templates[$page] ?? null;
            if (is_string($template) && !isset($pages[$page])) {
                $pages[$page] = $template;
            }
        }
        if ($pages !== []) {
            $webUi['templates'] = $pages;
        }
        $config['web_ui'] = $webUi;

        return $config;
    }
}
