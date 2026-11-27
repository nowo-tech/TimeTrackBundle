<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection;

use DateTimeZone;
use Nowo\TimeTrackBundle\Bridge\NullTeamContextProvider;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Calendar\MicrosoftCalendarOAuthClient;
use Nowo\TimeTrackBundle\Client\ConfigDeviceScopeFamilyProvider;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamily;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyRegistry;
use Nowo\TimeTrackBundle\UserRef\UserMapping;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_key_exists;
use function array_map;
use function array_unique;
use function class_exists;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Configuration tree for TimeTrackBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_time_track';

    /**
     * @var list<string> Host CSS stacks of the 1.x `templates.css_framework` (REQ-UI-001)
     *
     * @deprecated since 2.0 (the key is removed; set nowo_ui_kit.css_framework); removed in 3.0
     */
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

    /**
     * Semantic version 2.0.0 (core, optional pre-release and build metadata; no leading zeros, no `v` prefix).
     */
    public const SEMVER_PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?\z/';

    /** Exact https origin (`https://host[:port]`) of `client_api.task_url.origin`: DNS labels, no empty label or trailing dot, port 1–65535 (EF-I3). */
    public const HTTPS_ORIGIN_PATTERN = '~\Ahttps://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::(?:[1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5]))?\z~i';

    /** 2.0: the build error of the 1.x `templates.*` keys (deprecated since 1.8). */
    public const TEMPLATES_REMOVED = 'The nowo_time_track.templates keys (deprecated since 1.8) were removed in 2.0: use nowo_time_track.web_ui.layout_template (templates.layout; null = nowo_ui_kit.layouts.admin), nowo_time_track.web_ui.templates.index / .reports (templates.index / .reports) and nowo_ui_kit.css_framework (templates.css_framework); see UPGRADING "To 2.0.0".';

    /** 2.0: the build error of `security.manager_can_edit_entries: false` (deprecated since 1.5). */
    public const MANAGER_CAN_EDIT_ENTRIES_REMOVED = 'nowo_time_track.security.manager_can_edit_entries: false is no longer supported (the option, deprecated since 1.5, was removed in 2.0): remove it and refuse team managers the edit in a "nowo_time_track.time_entry.access_check" listener (TimeEntryAccessCheckEvent); see UPGRADING "To 2.0.0".';

    /** 2.0: the build error of a `heartbeat` route (`POST heartbeat`, deprecated since 1.9, is removed). */
    public const HEARTBEAT_REMOVED = 'The client API route "heartbeat" (POST /api/time-track/heartbeat, deprecated since 1.9) was removed in 2.0: remove "heartbeat" from nowo_time_track.clients.routes and nowo_time_track.client_api.routes.include. Clients answer idle time with "timer/discard-idle"; see UPGRADING "To 2.0.0".';

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
                // 2.0: the 1.x `templates.*` keys (deprecated since 1.8) are removed.
                ->ifTrue(static fn (mixed $v): bool => is_array($v) && array_key_exists('templates', $v))
                ->then(static fn (): never => throw new InvalidConfigurationException(self::TEMPLATES_REMOVED))
            ->end();
        $this->addUserClassToSecuritySections($root);
        $this->addRoutesToRetentionSections($root);
        $this->addWebUiSection($root);
        $this->addClientsSection($root);
        $this->addClientApiSection($root);
        $this->addClientDistributionSection($root);

        return $treeBuilder;
    }

    /**
     * Adds the `user_class` to `security` settings.
     */
    private function addUserClassToSecuritySections(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->scalarNode('user_class')
                    ->info('FQCN of the application user entity (must implement UserInterface and expose getId()).')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->example('App\\Entity\\User')
                ->end()
                ->arrayNode('accepted_user_classes')
                    ->info('Since 1.19 (JV-T3): other security user classes (wrappers / DTOs of a hexagonal host; classes only, no interfaces) whose getId() is a user_class id. Default: none, only user_class instances are stored.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $v): bool => !is_string($v) || !class_exists($v))
                            ->thenInvalid('accepted_user_classes takes existing concrete security user class names (no interface such as UserInterface: it would accept every user again), got %s.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->enumNode('user_mapping')
                    ->info('Since 1.23.0 (REQ-ARCH-001 rule 2): how the bundle rows store their user. relation (default, the 1.22 behaviour): Doctrine relations to user_class (foreign keys to your users table). id: the user class and id in a bundle-owned row (table <table_prefix>host_users, string id, binary collation on MySQL / MariaDB); user_class need not be a Doctrine entity of the bundle entity manager.')
                    ->values(UserMapping::values())
                    ->defaultValue(UserMapping::RELATION)
                ->end()
                ->scalarNode('service_account_policy')
                    ->info('Since 1.25.0 (Beacon spec 234): service id implementing Integration\\ServiceAccountPolicyInterface (isServiceAccount(TimeTrackUserRef): bool). Null (default): NoServiceAccountPolicy, everyone is a person. A service account (agent) may have overlapping entries; its entries are marked service_account.')
                    ->defaultNull()
                ->end()
                ->scalarNode('table_prefix')
                    ->info('Prefix for time track tables (entries, active_timers, client_tokens).')
                    ->defaultValue('time_track_')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('task_provider')
                    ->info('Service id implementing Integration\\TaskDirectoryInterface (optionally BatchTaskDirectoryInterface). Defaults to StubTaskProvider for demos.')
                    ->defaultValue(StubTaskProvider::class)
                ->end()
                ->scalarNode('team_context_provider')
                    ->info('Service id implementing Integration\\TeamDirectoryInterface.')
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
                        ->enumNode('mapping_type')
                            ->info('Since 1.23.0 (REQ-ARCH-001 rule 2): how the bundle entities are mapped: attribute (default) or xml (src/Resources/config/doctrine, the same mapping, for hosts that map everything in XML).')
                            ->values(['attribute', 'xml'])
                            ->defaultValue('attribute')
                        ->end()
                        ->booleanNode('private_entity_manager')
                            ->info('Since 2.0 the bundle rows are always written and read through a private entity manager created on the connection and mapping of `entity_manager` (REQ-ARCH-001 rule 6). `true` has no effect (deprecated, removed in 3.0); `false` fails the container build (see UPGRADING "To 2.0.0").')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/time-track-bundle', '2.0', 'The "%path%.%node%" option has no effect since 2.0 (the private entity manager is the only mode); remove it.')
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
                            ->info('Removed in 2.0 (deprecated since 1.5): team managers may edit entries in TeamAccessGuard::canEditEntry(); decide edit access in a "nowo_time_track.time_entry.access_check" listener (TimeEntryAccessCheckEvent). false fails the container build, true has no effect (removed in 3.0).')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/time-track-bundle', '2.0', 'The "%path%.%node%" option has no effect since 2.0; remove it and decide edit access in a "nowo_time_track.time_entry.access_check" listener (TimeEntryAccessCheckEvent).')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `routes` to `retention` settings.
     */
    private function addRoutesToRetentionSections(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
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
                        ->arrayNode('device_verify')
                            ->info('Default device verification page (1.9; only routed when client_api.device_flow.enabled and default_page are true). Session-authenticated: keep it behind the host firewall.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/tools/time-track/device')->end()
                                ->scalarNode('name')->defaultValue('nowo_time_track_device_verify')->end()
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
                ->arrayNode('imports')
                    ->info('Since 1.11: imported time entries (source "import", written by Import\\ImportedTimeEntryWriter). Descriptions are kept in full (no length cap).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_entry_seconds')
                            ->info('Longest single imported entry, in seconds (default 31 days); longer entries are refused.')
                            ->min(3600)
                            ->max(31_622_400)
                            ->defaultValue(2_678_400)
                        ->end()
                    ->end()
                ->end()
                ->append($this->workingTimeNode())
                ->append($this->timesheetsNode())
                ->append($this->calendarNode())
                ->append($this->manualEntriesNode())
                ->append($this->entriesNode())
                ->append($this->meetingsNode())
                ->arrayNode('retention')
                    ->info('Since 1.11: retention policy (Retention\\TimeEntryRetentionPolicy) that any purge must apply.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('include_imported')
                            ->info('false (default) = imported entries are history and stay out of retention purges.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('anonymise_on_user_delete')
                            ->info('Since 1.15. false (default) = a user removed through the entity manager leaves their entries under their name. true = the hours stay and the person goes, as with FormerPersonTimeEntries::anonymiseUser().')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('former_person_listener')
                            ->info('Since 1.19. true (default) = FormerPersonSnapshotListener reacts to user_class removals on the entity manager. false = no listener on your entity manager (DDD hosts that delete users through their own flow call FormerPersonTimeEntries::release() / anonymiseUser() themselves).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `web_ui` settings (the 1.x `templates` keys are removed in 2.0).
     */
    private function addWebUiSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('web_ui')
                    ->info('Manage UI pages on the UiKit page contract (REQ-UI-003).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Layout of the pages (passed to the UiKit scaffolds as ListPage::$layout). null = nowo_ui_kit.layouts.admin. Since 2.0 a layout without the UiKit page header block fails the page (the 1.x bridge is removed).')
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
            ->end();
    }

    /**
     * Adds the `enabled` to `login_rate_limit` settings.
     */
    private function addClientsEnabledToLoginRateLimitSections(ArrayNodeDefinition $clients): void
    {
        $clients
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
                    ->info('Exact allowed CORS origins, merged with client_api.allowed_origins (e.g. chrome-extension://<extension-id>). "*" fails the container build since 2.0 (deprecated since 1.9).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('cors_allow_any_extension_origin')
                    ->info('Removed in 2.0 (deprecated since 1.9): true fails the container build, false has no effect (removed in 3.0). List the exact extension origins in client_api.allowed_origins.')
                    ->defaultNull()
                    ->setDeprecated('nowo-tech/time-track-bundle', '2.0', 'The "%path%.%node%" option has no effect since 2.0 (the client API allows exact origins only); remove it.')
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
                        ->integerNode('max_attempts_per_username')
                            ->info('Failed logins per username (any client IP) within interval_seconds. null = 5 × max_attempts.')
                            ->defaultNull()
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `login` to `entries` settings.
     */
    private function addClientsRoutesLoginToEntriesSections(ArrayNodeDefinition $routes): void
    {
        $routes
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
                ->arrayNode('entries')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/entries')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_entries')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `timer_switch` to `timesheets` settings.
     */
    private function addClientsRoutesTimerSwitchToTimesheetsSections(ArrayNodeDefinition $routes): void
    {
        $routes
            ->children()
                ->arrayNode('timer_switch')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/timer/switch')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timer_switch')->end()
                    ->end()
                ->end()
                ->arrayNode('timer_discard_idle')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/timer/discard-idle')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timer_discard_idle')->end()
                    ->end()
                ->end()
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/export')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_export')->end()
                    ->end()
                ->end()
                ->arrayNode('device_code')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/device/code')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_device_code')->end()
                    ->end()
                ->end()
                ->arrayNode('device_token')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/device/token')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_device_token')->end()
                    ->end()
                ->end()
                // Since 1.13 (contract §10.2): working time.
                ->arrayNode('workday')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/workday')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_workday')->end()
                    ->end()
                ->end()
                ->arrayNode('workday_action')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/workday/{action}')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_workday_action')->end()
                    ->end()
                ->end()
                ->arrayNode('balance')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/balance')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_balance')->end()
                    ->end()
                ->end()
                ->arrayNode('timesheets')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/timesheets')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timesheets')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `timesheet_submit` to `entries_create` settings.
     */
    private function addClientsRoutesTimesheetSubmitToEntriesCreateSections(ArrayNodeDefinition $routes): void
    {
        $routes
            ->children()
                ->arrayNode('timesheet_submit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/timesheets/{periodKey}/submit')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timesheet_submit')->end()
                    ->end()
                ->end()
                ->arrayNode('timesheet_withdraw')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/timesheets/{periodKey}/withdraw')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timesheet_withdraw')->end()
                    ->end()
                ->end()
                // Since 1.14 (contract §10.3).
                ->arrayNode('calendar_connections')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/calendar/connections')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_calendar_connections')->end()
                    ->end()
                ->end()
                ->arrayNode('calendar_connection_revoke')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/calendar/connections/{id}')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_calendar_connection_revoke')->end()
                    ->end()
                ->end()
                ->arrayNode('suggestions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/suggestions')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_suggestions')->end()
                    ->end()
                ->end()
                ->arrayNode('suggestions_accept')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/suggestions/accept')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_suggestions_accept')->end()
                    ->end()
                ->end()
                ->arrayNode('suggestions_dismiss')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/suggestions/dismiss')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_suggestions_dismiss')->end()
                    ->end()
                ->end()
                ->arrayNode('suggestions_resolve_keys')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/suggestions/resolve-keys')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_suggestions_resolve_keys')->end()
                    ->end()
                ->end()
                ->arrayNode('entries_create')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/entries')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_entries_create')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `entries_update` to `monitoring_activity` settings (and since 2.1.0 `timer_allocate`, `subjects_search`,
     * `activity_types`).
     */
    private function addClientsRoutesEntriesUpdateToMonitoringActivitySections(ArrayNodeDefinition $routes): void
    {
        $routes
            ->children()
                // Since 1.21: PATCH entries/{id} (billable).
                ->arrayNode('entries_update')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/entries/{id}')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_entries_update')->end()
                    ->end()
                ->end()
                // Since 1.14 (contract §22.2).
                ->arrayNode('me_meetings')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/me/meetings')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_client_me_meetings')->end()
                    ->end()
                ->end()
                // Since 1.26 (contract §24, spec 199): activity monitoring (404 while monitoring is off).
                ->arrayNode('monitoring_acknowledge')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/monitoring/acknowledge')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_monitoring_acknowledge')->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring_screenshot_intent')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/monitoring/screenshots/intent')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_monitoring_screenshot_intent')->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring_upload')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/monitoring/uploads/{uploadId}')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_monitoring_upload')->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring_screenshot_discarded')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/monitoring/screenshots/discarded')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_monitoring_screenshot_discarded')->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring_screenshot_delete')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/monitoring/screenshots/{screenshotId}')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_monitoring_screenshot_delete')->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring_activity')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/monitoring/activity')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_monitoring_activity')->end()
                    ->end()
                ->end()
                // Since 2.1.0 (contract v0.4, Beacon spec 256): subjects and activity types.
                ->arrayNode('timer_allocate')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/timer/allocate')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_timer_allocate')->end()
                    ->end()
                ->end()
                ->arrayNode('subjects_search')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/subjects/search')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_subjects_search')->end()
                    ->end()
                ->end()
                ->arrayNode('activity_types')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/api/time-track/activity-types')->end()
                        ->scalarNode('name')->defaultValue('nowo_time_track_api_activity_types')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `routes` section; its large children have their own methods.
     */
    private function addClientsRoutesSection(ArrayNodeDefinition $clients): void
    {
        $routes = $clients->children()->arrayNode('routes');
        $routes
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
                // 2.0: POST heartbeat (deprecated since 1.9, notices since 1.33.0) is removed with its route.
                ->ifTrue(static fn (mixed $v): bool => is_array($v) && array_key_exists('heartbeat', $v))
                ->then(static fn (): never => throw new InvalidConfigurationException(self::HEARTBEAT_REMOVED))
            ->end();
        $this->addClientsRoutesLoginToEntriesSections($routes);
        $this->addClientsRoutesTimerSwitchToTimesheetsSections($routes);
        $this->addClientsRoutesTimesheetSubmitToEntriesCreateSections($routes);
        $this->addClientsRoutesEntriesUpdateToMonitoringActivitySections($routes);
    }

    /**
     * Adds the `clients` section; its large children have their own methods.
     */
    private function addClientsSection(ArrayNodeDefinition $rootNode): void
    {
        $clients = $rootNode->children()->arrayNode('clients');
        $clients
            ->info('Browser extension and Tauri desktop agent API (Bearer token auth).')
            ->addDefaultsIfNotSet();
        $this->addClientsEnabledToLoginRateLimitSections($clients);
        $this->addClientsRoutesSection($clients);
    }

    /**
     * Adds the `prefix` to `workday_provider` settings.
     */
    private function addClientApiPrefixToWorkdayProviderSections(ArrayNodeDefinition $clientApi): void
    {
        $clientApi
            ->children()
                ->scalarNode('prefix')
                    ->info('Since 1.29 (REQ-API-001): host API prefix of the versioned client routes, served at <route_prefix><prefix>/time-track/v1/… (default /api → /api/time-track/v1/…). The unversioned clients.routes.* paths stay as permanent aliases. Empty string or a path starting with "/" and not ending with "/".')
                    ->defaultValue('/api')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || ($v !== '' && (!str_starts_with($v, '/') || str_ends_with($v, '/') || preg_match('#\s#', $v) === 1)))
                        ->thenInvalid('client_api.prefix must be empty or a path starting with "/" and not ending with "/": %s')
                    ->end()
                ->end()
                ->arrayNode('routes')
                    ->info('Since 1.30: which client API routes the route import publishes.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('include')
                            ->info('Since 1.30: list of client route keys to publish (for example [login, me, tasks, timer, timer_start, timer_stop, entries]); applies to the unversioned paths and the v1 twins alike. A key that is not listed is not routed at all (the router answers 404). Empty (default) = every client route. Unknown keys fail the container build.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('user_checker')
                    ->info('Since 1.29 (Beacon C5): run a Symfony user checker (checkPreAuth + checkPostAuth) for the user of every Bearer token use; a disabled, locked or expired account gets 401 invalid_token. On by default. `false` turns it off; a string is the checker service id.')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifTrue(static fn (mixed $v): bool => is_bool($v))
                        ->then(static fn (bool $v): array => ['enabled' => $v])
                    ->end()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(static fn (string $v): array => ['service' => $v])
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('service')
                            ->info('Explicit user checker service id (Symfony UserCheckerInterface); wins over firewall. Null = security.user_checker.<firewall> (see firewall); none found = no check.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('firewall')
                            ->info('Firewall whose user checker (security.user_checker.<firewall>, incl. chained checkers such as UserKit) runs. Null = the firewall named "main" when present, else the first firewall with security enabled.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('revoke_tokens')
                            ->info('Delete the refused token (Beacon ActiveAccountClientTokens behaviour): the client signs in again after the account is re-enabled. false keeps it, so the token works again once the account is re-enabled.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('required')
                            ->info('Fail the container build when the resolved checker refuses nobody: none found, or Symfony\'s default security.user_checker (InMemoryUserChecker, which ignores entity users). false (default): the build logs it and sets %nowo_time_track.client_api.user_checker.effective% to false.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => !$v['enabled'] && $v['required'])
                        ->thenInvalid('client_api.user_checker.required cannot be true while the user checker is turned off.')
                    ->end()
                ->end()
                ->arrayNode('allowed_origins')
                    ->info('Exact CORS origins: chrome-extension://<id>, moz-extension://<uuid>, extension://<id> (Edge). No wildcard. The desktop app sends no Origin.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $v): bool => !is_string($v) || $v === '' || str_contains($v, '*'))
                            ->thenInvalid('client_api.allowed_origins takes exact origins only (no wildcard): %s')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('allow_tauri_webview_origins')
                    ->info('Removed in 2.0: the client API no longer allows the Tauri webview origins of the 1.x desktop agent. true fails the container build, false has no effect (removed in 3.0).')
                    ->defaultNull()
                    ->setDeprecated('nowo-tech/time-track-bundle', '2.0', 'The "%path%.%node%" option has no effect since 2.0 (the client API no longer allows the Tauri webview origins); remove it.')
                ->end()
                ->booleanNode('allow_metadata')
                    ->info('Accept free client "metadata" on timer/start (default false: rejected with 400 metadata_not_allowed; clients send source + note).')
                    ->defaultFalse()
                ->end()
                ->scalarNode('settings_provider')
                    ->info('Service id implementing ClientApiSettingsProviderInterface (null = these config values).')
                    ->defaultNull()
                ->end()
                ->scalarNode('credential_state')
                    ->info('Service id implementing ClientCredentialStateInterface, checked on every request (null = tokens stay valid until expiry / logout).')
                    ->defaultNull()
                ->end()
                ->scalarNode('credential_issuer')
                    ->info('Service id implementing Client\\ClientCredentialMinterInterface, called when a device is approved (null = opaque device:<uuid> ids).')
                    ->defaultNull()
                ->end()
                ->scalarNode('task_access_checker')
                    ->info('Service id implementing Security\\TaskAccessPolicyInterface, checked on timer/start and timer/switch (null = the task provider decides).')
                    ->defaultNull()
                ->end()
                ->scalarNode('profile_provider')
                    ->info('Service id implementing Client\\ClientUserProfileSourceInterface (displayName / workingHours of GET me; null = none).')
                    ->defaultNull()
                ->end()
                ->scalarNode('workday_provider')
                    ->info('Since 1.13: service id implementing Workday\\WorkdaySourceInterface (optionally WorkdayHistorySourceInterface; R-GDPR §8; null = no workday API).')
                    ->defaultNull()
                ->end()
            ->end();
    }

    /**
     * Adds the `links_working_time_route` to `task_url` settings.
     */
    private function addClientApiLinksWorkingTimeRouteToTaskUrlSections(ArrayNodeDefinition $clientApi): void
    {
        $clientApi
            ->children()
                ->scalarNode('links_working_time_route')
                    ->info('Since 1.19 (spec 198): host route name of the working-time page (GET me links.workingTime, only while features.workday is true; https only).')
                    ->defaultNull()
                ->end()
                ->scalarNode('leave_provider')
                    ->info('Since 1.22 (spec 204): service id implementing Client\\ClientUserLeaveProviderInterface (GET workday leave, GET me features.leave / links.leave; null = no leave keys, as in 1.21).')
                    ->defaultNull()
                ->end()
                ->scalarNode('links_leave_route')
                    ->info('Since 1.22 (spec 204): host route name of the Leave page (GET me links.leave, sent only with a leave_provider; https only).')
                    ->defaultNull()
                ->end()
                ->arrayNode('backdate')
                    ->info('Client-supplied startedAt (timer/start) and endedAt (timer/stop), 1.10: clamped and reported, never rejected.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_seconds')
                            ->info('How far back startedAt may go (default and maximum 24 h; 0 = startedAt / endedAt ignored, reason "disabled").')
                            ->defaultValue(86400)->min(0)->max(86400)
                        ->end()
                        ->integerNode('clock_skew_seconds')
                            ->info('Future tolerance read as "now" (beyond it the value is clamped with reason "future").')
                            ->defaultValue(120)->min(0)->max(900)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('idle')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('allow_reassign')
                            ->info('Accept timer/discard-idle action "reassign" (1.10; false = 400 invalid_request and features.idle_reassign false).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('idle_check')
                    ->info('Idle-check settings published on GET me (1.10). The server only receives the user\'s decision (timer/discard-idle); no activity counter is sent or stored.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('threshold_minutes')->info('Idle minutes before the client asks.')->defaultValue(10)->min(5)->max(60)->end()
                        ->integerNode('answer_timeout_minutes')->info('Minutes the idle prompt waits for an answer.')->defaultValue(5)->min(1)->max(30)->end()
                        ->booleanNode('user_can_disable')->info('Whether users may turn the idle check off in the client (1.10.1; limits.idle_check.user_can_disable).')->defaultFalse()->end()
                        ->scalarNode('provider')
                            ->info('Service id implementing Client\\IdleCheckSettingsPolicyInterface (per-user settings; null = these values). Its values are clamped to the same ranges.')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('task_url')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('origin')
                            ->info('Expected origin of task URLs on tasks / timer (exact https://host[:port]; null = the request origin). Behind a proxy set it from the public URL, e.g. "%beacon.app_url%".')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match(self::HTTPS_ORIGIN_PATTERN, $v) !== 1))
                                ->thenInvalid('client_api.task_url.origin must be an exact origin "https://host[:port]" (no path, query, trailing slash or credentials), got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `rate_limit` to `monitoring` settings.
     */
    private function addClientApiRateLimitToMonitoringSections(ArrayNodeDefinition $clientApi): void
    {
        $clientApi
            ->children()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')
                            ->info('PSR-6 pool shared by all workers (Redis, filesystem, APCu; not an array pool).')
                            ->defaultValue('cache.app')
                            ->cannotBeEmpty()
                        ->end()
                        ->integerNode('per_token')->info('Requests per token per window (0 = off).')->defaultValue(60)->min(0)->end()
                        ->integerNode('per_ip')->info('Requests per client IP per window on every API route (0 = off).')->defaultValue(300)->min(0)->end()
                        ->integerNode('device_per_ip')->info('Requests per client IP per window on device/code and device/token (0 = off).')->defaultValue(30)->min(0)->end()
                        ->integerNode('window_seconds')->defaultValue(60)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('audit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('denial_interval_seconds')
                            ->info('At most one request_denied event per credential (or IP prefix) and reason per interval.')
                            ->defaultValue(60)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('monitoring')
                    ->info('Since 1.26 (contract §24, spec 199): activity monitoring, opt-in. Off (no GET me block, every monitoring route 404) until policy_source and evidence_store are set. Organisations enable it only with a recorded DPIA and the notice shown first.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('policy_source')
                            ->info('Service id implementing Monitoring\\MonitoringPolicySourceInterface (null = monitoring off).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('evidence_store')
                            ->info('Service id implementing Monitoring\\MonitoringEvidenceStoreInterface (required with policy_source; the bundle never writes files).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('acknowledgement_store')
                            ->info('Service id implementing Monitoring\\MonitoringAcknowledgementStoreInterface (null = the bundle table, through the bundle entity manager; aliasing the interface works too).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('signer')
                            ->info('Service id implementing Monitoring\\MonitoringUploadSignerInterface (null = HMAC-SHA256 with a key derived from signing_key or kernel.secret).')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('signing_key')
                            ->info('Secret the default signer derives its key from (null = kernel.secret). Use an env var, e.g. "%env(TIME_TRACK_MONITORING_KEY)%".')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('upload_base_url')
                            ->info('Since 1.26 (audit PM-L2): exact origin "https://host[:port]" (one trailing "/" allowed) of the signed upload URLs, e.g. "%env(DEFAULT_URI)%" (checked at runtime: anything else answers 503); null = the request scheme and host, then set framework.trusted_hosts / trusted_proxies.')
                            ->defaultNull()
                            ->validate()
                                // An env placeholder (seen here as '' or the raw %env()%) is checked at runtime (audit PM delta D1-L1).
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || ($v !== '' && !str_contains($v, '%env(') && preg_match(self::HTTPS_ORIGIN_PATTERN, (string) preg_replace('~/\z~', '', $v)) !== 1)))
                                ->thenInvalid('client_api.monitoring.upload_base_url must be an exact origin "https://host[:port]" (no path, query, trailing slash or credentials), got %s.')
                            ->end()
                        ->end()
                        ->arrayNode('rate_limit')
                            ->info('Per token and 10-minute window (0 = off), on top of client_api.rate_limit.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('intents_per_10_min')->info('POST screenshots/intent (and, each on its own counter, screenshots/discarded and DELETE screenshots/{id}).')->defaultValue(6)->min(0)->end()
                                ->integerNode('uploads_per_10_min')->info('PUT uploads/{uploadId}.')->defaultValue(6)->min(0)->end()
                                ->integerNode('activity_per_10_min')->info('POST activity.')->defaultValue(30)->min(0)->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => is_string($v['policy_source']) && $v['policy_source'] !== '' && (!is_string($v['evidence_store']) || $v['evidence_store'] === ''))
                        ->thenInvalid('client_api.monitoring.evidence_store is required when client_api.monitoring.policy_source is set.')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `device_flow` section.
     */
    private function addClientApiDeviceFlowSection(ArrayNodeDefinition $clientApi): void
    {
        $clientApi
            ->children()
                ->arrayNode('device_flow')
                    ->info('Device flow of the desktop app (RFC 8628 shape). Opt-in.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->booleanNode('default_page')
                            ->info('Route the bundle verification page (routes.device_verify). false when the host renders its own page with DeviceAuthorizationService.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('verification_uri')
                            ->info('Absolute URL of the verification page sent to the device (null = the default page URL; required when default_page is false).')
                            ->defaultNull()
                        ->end()
                        ->integerNode('code_ttl')->defaultValue(600)->min(60)->max(3600)->end()
                        ->integerNode('interval')->defaultValue(5)->min(1)->max(60)->end()
                        ->integerNode('lookup_limit')->info('User code lookups per user per lookup_window_seconds.')->defaultValue(10)->min(1)->end()
                        ->integerNode('lookup_window_seconds')->defaultValue(900)->min(60)->end()
                        ->integerNode('max_pending_per_prefix')->info('Device requests created per client IPv4 /24 or IPv6 /64 within code_ttl (0 = off).')->defaultValue(20)->min(0)->end()
                        ->integerNode('max_pending')->info('Pending, not expired device requests in total; device/code answers 429 above it (0 = off).')->defaultValue(10000)->min(0)->end()
                        ->arrayNode('scope_families')
                            ->info('Since 2.2.0: extra scope families of the device flow (key => label and scope => label), e.g. chat: {label: Chat, scopes: {"chat:read": "Read your conversations"}}. The time family is the bundle\'s own; one token per family; deny by default. Services implementing DeviceScopeFamilyProviderInterface add more.')
                            ->useAttributeAsKey('key')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                                    ->arrayNode('scopes')
                                        ->isRequired()
                                        ->requiresAtLeastOneElement()
                                        ->useAttributeAsKey('scope')
                                        ->scalarPrototype()->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->validate()
                                ->always(static function (array $families): array {
                                    foreach ($families as $key => $family) {
                                        // Builds each family once: a malformed key, label or scope fails the build.
                                        new DeviceScopeFamily((string) $key, (string) $family['label'], $family['scopes']);
                                    }
                                    if (isset($families[DeviceScopeFamily::TIME])) {
                                        throw new InvalidConfigurationException('client_api.device_flow.scope_families cannot define the "time" family: it belongs to TimeTrackBundle.');
                                    }
                                    (new DeviceScopeFamilyRegistry([new ConfigDeviceScopeFamilyProvider($families)]))->all();

                                    return $families;
                                })
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['enabled'] && !$v['default_page'] && !is_string($v['verification_uri']))
                        ->thenInvalid('client_api.device_flow.verification_uri is required when default_page is false.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => is_string($v['verification_uri']) && preg_match('#^https://#i', $v['verification_uri']) !== 1 && preg_match('#^http://(localhost|127\.0\.0\.1)(:|/|$)#i', $v['verification_uri']) !== 1)
                        ->thenInvalid('client_api.device_flow.verification_uri must be an absolute https:// URL.')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `client_api` section; its large children have their own methods.
     */
    private function addClientApiSection(ArrayNodeDefinition $rootNode): void
    {
        $clientApi = $rootNode->children()->arrayNode('client_api');
        $clientApi
            ->info('Client API 1.9 (contract v0.2): host interfaces, CORS, metadata, rate limits, audit and device flow. Needs clients.enabled.')
            ->addDefaultsIfNotSet();
        $this->addClientApiPrefixToWorkdayProviderSections($clientApi);
        $this->addClientApiLinksWorkingTimeRouteToTaskUrlSections($clientApi);
        $this->addClientApiRateLimitToMonitoringSections($clientApi);
        $this->addClientApiDeviceFlowSection($clientApi);
        $this->addClientApiContextSection($clientApi);
    }

    /**
     * Since 2.2.0: adds the `context` section (explicit tenant context header, Beacon Desktop Q-BD-7).
     */
    private function addClientApiContextSection(ArrayNodeDefinition $clientApi): void
    {
        $clientApi
            ->children()
                ->arrayNode('context')
                    ->info('Since 2.2.0: explicit tenant context of client API requests (a header checked on every request by a host ClientContextPolicyInterface; the user\'s stored web context is never changed). Off without a policy.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('policy')->info('Service id implementing ClientContextPolicyInterface (null = off: the header is ignored).')->defaultNull()->end()
                        ->scalarNode('header')
                            ->info('Name of the context header (Beacon: X-Beacon-Context).')
                            ->defaultValue('X-Client-Context')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/\A[A-Za-z][A-Za-z0-9-]{0,63}\z/', $v) !== 1 || in_array(strtolower($v), ['authorization', 'content-type', 'idempotency-key', 'origin', 'cookie', 'host'], true))
                                ->thenInvalid('client_api.context.header must be a header name of letters, digits and dashes (at most 64), not a standard header: %s')
                            ->end()
                        ->end()
                        ->booleanNode('required')->info('Refuse a request without the header (400 context_required); false = no header means no context.')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `client_distribution` section.
     */
    private function addClientDistributionSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('client_distribution')
                    ->info('Download / store links of the clients (ClientDistributionProviderInterface default). Absolute http(s) URLs only.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('provider')->info('Service id implementing ClientDistributionProviderInterface (null = these links).')->defaultNull()->end()
                        ->arrayNode('extension')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('chrome')->defaultNull()->end()
                                ->scalarNode('edge')->defaultNull()->end()
                                ->scalarNode('firefox')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('desktop')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('windows')->defaultNull()->end()
                                ->scalarNode('macos')->defaultNull()->end()
                                ->scalarNode('linux')->defaultNull()->end()
                                ->scalarNode('latest_version')->defaultNull()->end()
                                ->scalarNode('update_manifest_url')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('min_supported_version')
                            ->info('Oldest supported client version per client type, returned by GET me as minSupportedVersion (semver; null = key absent).')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('provider')->info('Service id implementing ClientMinSupportedVersionProviderInterface (null = these versions).')->defaultNull()->end()
                                ->scalarNode('extension')
                                    ->defaultNull()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match(self::SEMVER_PATTERN, $v) !== 1))
                                        ->thenInvalid('client_distribution.min_supported_version.extension must be a semantic version (e.g. 1.4.0) or null.')
                                    ->end()
                                ->end()
                                ->scalarNode('desktop')
                                    ->defaultNull()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match(self::SEMVER_PATTERN, $v) !== 1))
                                        ->thenInvalid('client_distribution.min_supported_version.desktop must be a semantic version (e.g. 1.4.0) or null.')
                                    ->end()
                                ->end()
                                ->scalarNode('web')
                                    ->defaultNull()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match(self::SEMVER_PATTERN, $v) !== 1))
                                        ->thenInvalid('client_distribution.min_supported_version.web must be a semantic version (e.g. 1.4.0) or null.')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `enabled` to `http_client` settings.
     */
    private function addEnabledToHttpClientSections(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->info('Calendar suggestions, their client API and the sync command (default off).')
                    ->defaultFalse()
                ->end()
                ->scalarNode('credential_provider')
                    ->info('Service id implementing Calendar\\CalendarCredentialSourceInterface (host OAuth tokens; required for server mode).')
                    ->defaultNull()
                ->end()
                ->scalarNode('task_key_resolver')
                    ->info('Service id implementing Calendar\\TaskKeyLookupInterface (host task keys; required when enabled).')
                    ->defaultNull()
                ->end()
                ->arrayNode('providers')
                    ->info('Enabled providers.')
                    ->enumPrototype()->values(['google', 'microsoft'])->end()
                    ->defaultValue(['google', 'microsoft'])
                ->end()
                ->arrayNode('modes')
                    ->info('Enabled modes: server (bundle adapters, host tokens) and/or local (the desktop reads the calendar).')
                    ->enumPrototype()->values(['server', 'local'])->end()
                    ->defaultValue(['server'])
                ->end()
                ->integerNode('lookback_days')->info('Past days read per sync (events end before now; future events are never suggested).')->defaultValue(14)->min(1)->max(14)->end()
                ->integerNode('suggestion_ttl_days')->info('Days a suggestion is kept after its event ends.')->defaultValue(14)->min(1)->max(31)->end()
                ->integerNode('sync_interval_minutes')->info('Minutes between two scheduled syncs of one connection.')->defaultValue(60)->min(5)->max(1440)->end()
                ->integerNode('max_event_seconds')->info('Longer events are ignored (default 8 h).')->defaultValue(28_800)->min(60)->max(86_400)->end()
                ->integerNode('max_rules_per_user')->info('User rules per scope.')->defaultValue(20)->min(0)->max(100)->end()
                ->integerNode('max_pages')->info('Provider pages read per sync.')->defaultValue(10)->min(1)->max(50)->end()
                ->enumNode('microsoft_scope')
                    ->info('Since 1.19: granted Graph scope — Calendars.ReadBasic (no body: task keys from titles) or Calendars.Read (body too).')
                    ->values(['Calendars.ReadBasic', 'Calendars.Read'])
                    ->defaultValue('Calendars.ReadBasic')
                ->end()
                ->arrayNode('microsoft_extra_scopes')
                    ->info('Since 2.3: allow-list of extra delegated Graph scopes the host may request with incremental consent through the Microsoft OAuth helper (e.g. OnlineMeetings.ReadWrite; default none: the 2.2 requests).')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $scope): bool => !is_string($scope) || preg_match(MicrosoftCalendarOAuthClient::EXTRA_SCOPE_PATTERN, $scope) !== 1)
                            ->thenInvalid('A Microsoft extra scope must be a Graph scope name such as "OnlineMeetings.ReadWrite" (no prefix, no OpenID scope); got %s.')
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $scopes): bool => count($scopes) > MicrosoftCalendarOAuthClient::MAX_EXTRA_SCOPES || count(array_unique(array_map(strtolower(...), $scopes))) !== count($scopes))
                        ->thenInvalid('calendar.microsoft_extra_scopes takes at most 10 distinct scopes; got %s.')
                    ->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('http_client')
                    ->info('Since 1.20 (audit ML-L1): service id of the HTTP client of the calendar readers and OAuth token calls (default: the framework http_client). Point it at an untraced client where the profiler is on.')
                    ->defaultValue('http_client')
                    ->cannotBeEmpty()
                ->end()
            ->end();
    }

    /**
     * Adds the `oauth` section.
     */
    private function addOauthSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('oauth')
                    ->info('Since 1.19: OAuth apps of the optional OAuth helpers (empty = not configured). Use %env()% for the secrets.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('app_provider')
                            ->info('Since 1.20: service id implementing CalendarOAuthAppProviderInterface (apps read at runtime on every call, e.g. encrypted in the database); null = the static google / microsoft values below.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || trim($v) === ''))
                                ->thenInvalid('calendar.oauth.app_provider must be a service id.')
                            ->end()
                        ->end()
                        ->arrayNode('google')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('client_id')->defaultValue('')->end()
                                ->scalarNode('client_secret')->defaultValue('')->end()
                            ->end()
                        ->end()
                        ->arrayNode('microsoft')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('client_id')->defaultValue('')->end()
                                ->scalarNode('client_secret')->defaultValue('')->end()
                                ->scalarNode('tenant')->info('common, organizations, consumers or a tenant id / domain.')->defaultValue('common')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Since 1.13: `working_time` (contract §4, §13): working calendar, worked-time source, overtime policy and the
     * hours balance. Off by default.
     */
    private function workingTimeNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('working_time'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Hours balance, overtime and the balance client API (default off).')
                    ->defaultFalse()
                ->end()
                ->scalarNode('calendar_provider')
                    ->info('Service id implementing WorkingTime\\WorkingCalendarSourceInterface (null = DerivedWorkingCalendar from the 1.12 working hours and days off).')
                    ->defaultNull()
                ->end()
                ->scalarNode('worked_time_source')
                    ->info('Service id implementing WorkingTime\\WorkedTimeRecordInterface (null = TaskTimeWorkedTimeSource: live task time, legalRecord false).')
                    ->defaultNull()
                ->end()
                ->scalarNode('overtime_policy')
                    ->info('Service id implementing OvertimePolicyInterface (null = ConfigOvertimePolicy with the values below).')
                    ->defaultNull()
                ->end()
                ->arrayNode('balance')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('start_date')
                            ->info('First day of the running balance (Y-m-d); null = the 366 days before the end of the asked range, or after the last locked period.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D', $v) !== 1))
                                ->thenInvalid('working_time.balance.start_date must be a Y-m-d date or null, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('overtime')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('daily_mode')
                            ->info('expected: above the day\'s expected time; ordinary_max: above the agreement cap or ordinary_max_seconds; none: weekly rule only (flexible hours).')
                            ->values(['expected', 'ordinary_max', 'none'])
                            ->defaultValue('expected')
                        ->end()
                        ->integerNode('ordinary_max_seconds')->info('9 h, art. 34.3 ET.')->defaultValue(32_400)->min(0)->max(86_400)->end()
                        ->enumNode('weekly_mode')
                            ->info('expected: above the week\'s expected time; legal_max: above weekly_max_seconds.')
                            ->values(['expected', 'legal_max'])
                            ->defaultValue('expected')
                        ->end()
                        ->integerNode('weekly_max_seconds')->info('40 h, art. 34.1 ET.')->defaultValue(144_000)->min(1)->max(604_800)->end()
                        ->integerNode('annual_cap_seconds')->info('80 h, art. 35.2 ET (0 = no cap flags).')->defaultValue(288_000)->min(0)->end()
                        ->booleanNode('ratify_on_approval')->info('Write one overtime_ratified ledger line when a timesheet is approved.')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Since 1.13: `timesheets` (contract §13): weekly / monthly timesheets with submit, approval, hard lock and audit
     * trail. Off by default; incoherent combinations are refused at compile time.
     */
    private function timesheetsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('timesheets'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Timesheets, period lock and the timesheet client API (default off).')
                    ->defaultFalse()
                ->end()
                ->enumNode('scope_mode')
                    ->info('provider: tenant scopes from scope_provider; single: one scope "" for everybody.')
                    ->values(['provider', 'single'])
                    ->defaultValue('provider')
                ->end()
                ->scalarNode('scope_provider')
                    ->info('Service id implementing Timesheet\\TimesheetScopePolicyInterface (required with scope_mode: provider).')
                    ->defaultNull()
                ->end()
                ->scalarNode('approver_provider')
                    ->info('Service id implementing Timesheet\\TimesheetApproverPolicyInterface (required when enabled).')
                    ->defaultNull()
                ->end()
                ->enumNode('period')
                    ->info('week (ISO, Monday to Sunday) or month.')
                    ->values(['week', 'month'])
                    ->defaultValue('week')
                ->end()
                ->scalarNode('default_timezone')
                    ->info('Zone a period is cut in when neither the scope nor the working hours give one.')
                    ->defaultValue('UTC')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || !in_array($v, DateTimeZone::listIdentifiers(), true))
                        ->thenInvalid('timesheets.default_timezone must be an IANA time zone, got %s.')
                    ->end()
                ->end()
                ->booleanNode('submit_requires_period_end')
                    ->info('Refuse a submit before the period has ended (default false: a Friday afternoon submit is allowed).')
                    ->defaultFalse()
                ->end()
                ->integerNode('auto_lock_after_days')
                    ->info('Lock every period N days after its end (nowo:time-track:timesheets:auto-lock); null = off.')
                    ->defaultNull()
                    ->min(1)
                    ->max(366)
                ->end()
                ->booleanNode('allow_self_approval')
                    ->info('Fixed false in 1.13: nobody approves their own timesheet, admin roles included.')
                    ->defaultFalse()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => $v !== false)
                        ->thenInvalid('timesheets.allow_self_approval cannot be enabled in 1.13 (no self-approval, contract Q-O2).')
                    ->end()
                ->end()
                ->booleanNode('admin_roles_view')
                    ->info('Whether security.admin_roles see timesheets of their scopes without being approvers (default false).')
                    ->defaultFalse()
                ->end()
                ->arrayNode('reopen_roles')
                    ->info('Roles that may reopen a locked period besides its approvers (default none).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('approver_sees_notes')
                    ->info('Whether approvers see entry notes and descriptions (default false: minimisation).')
                    ->defaultFalse()
                ->end()
                ->booleanNode('audit_blocked_edits')
                    ->info('Write an edit_blocked audit row per refused write (at most one per timesheet per minute).')
                    ->defaultFalse()
                ->end()
                ->integerNode('retention_years')
                    ->info('Years timesheets, their audit rows and the ledger are kept after the end of the period month (4-10).')
                    ->defaultValue(4)
                    ->min(4)
                    ->max(10)
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['enabled'] && $v['scope_mode'] === 'provider' && !is_string($v['scope_provider']))
                ->thenInvalid('timesheets.scope_provider is required when timesheets are enabled with scope_mode: provider.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['enabled'] && !is_string($v['approver_provider']))
                ->thenInvalid('timesheets.approver_provider is required when timesheets are enabled (nobody could approve).')
            ->end();

        return $node;
    }

    /**
     * Since 1.14: `calendar` (contract §6, §13): calendar suggestions from Google / Microsoft 365 events whose title
     * carries a task key. Off by default; on needs a task-key resolver, and server mode a credential provider.
     */
    private function calendarNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('calendar'))->getRootNode();
        $node
            ->addDefaultsIfNotSet();
        $this->addEnabledToHttpClientSections($node);
        $this->addOauthSection($node);
        $node
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['enabled'] && !is_string($v['task_key_resolver']))
                ->thenInvalid('calendar.task_key_resolver is required when calendar suggestions are enabled.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['enabled'] && in_array('server', $v['modes'], true) && !is_string($v['credential_provider']))
                ->thenInvalid('calendar.credential_provider is required when the server mode is enabled.')
            ->end();

        return $node;
    }

    /**
     * Since 1.36.0 (Beacon spec 250): `entries`: time entries without a task. Off by default. Since 2.1.0 (Beacon spec
     * 256) also the allocation requirements and the subject options.
     */
    private function entriesNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('entries'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('allow_without_task')
                    ->info('Since 1.36.0: false (default) = every timer and entry needs a task (or, since 2.1.0, a subject), as before. true = time with neither task nor subject is accepted, with an optional free-text description and a tenant scope that must be one of the user\'s. Such an entry is reached only by its owner and, for anyone else, within their own tenant scopes; never through task or board permissions. Since 2.1.0 the client API also accepts it on timer/start when require_allocation is off.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('require_allocation')
                    ->info('Since 2.1.0 (Beacon spec 256): true = an interactive stop (client API timer/stop, HostTimerApi::stop(), the web UI) or a manual entry with neither task nor subject is refused (409 allocation_required, the timer keeps running). Stops the bundle triggers itself (start-replacing, switch, idle resolution, user deletion) are never refused: they store the entry unallocated and dispatch TimeEntryUnallocatedEvent. Default false.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('require_activity_type')
                    ->info('Since 2.1.0: true = an interactive stop or a manual entry without an activity type is refused (activity_type_required). Effective only with an activity type provider (ActivityTypeProviderInterface). Default false.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('subjects')
                    ->info('Since 2.1.0 (Beacon spec 256): time subjects (customers, the organisation, ...). Implement Nowo\\TimeTrackBundle\\Subject\\TimeSubjectProviderInterface once per subject type; autoconfiguration registers it.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('scope_subject')
                            ->info('The built-in subject "the tenant itself" over the timesheet scope provider (internal time on the organisation). Off by default.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultFalse()->end()
                                ->scalarNode('type')
                                    ->info('Subject type of the built-in provider (^[a-z][a-z0-9_]{0,31}$).')
                                    ->defaultValue('organisation')
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $v) !== 1)
                                        ->thenInvalid('entries.subjects.scope_subject.type must match ^[a-z][a-z0-9_]{0,31}$.')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('search')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('max_per_minute')
                                    ->info('Client API GET subjects/search requests per token and minute.')
                                    ->defaultValue(60)
                                    ->min(1)
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Since 1.14: `manual_entries` (contract §10.3, Q-O7): `POST entries` for past entries without a timer (sources
     * `manual` and `calendar`). Off by default.
     */
    private function manualEntriesNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('manual_entries'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('POST entries on the client API (default off). Accepting calendar suggestions does not depend on it.')
                    ->defaultFalse()
                ->end()
                ->integerNode('max_age_days')
                    ->info('Oldest accepted start, in days before now.')
                    ->defaultValue(31)
                    ->min(1)
                    ->max(366)
                ->end()
            ->end();

        return $node;
    }

    /**
     * Since 1.14: `meetings` (contract §22.9, spec 210): the caller's own meetings on the client API from a host
     * provider. Off by default; enabled needs a provider.
     */
    private function meetingsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('meetings'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->info('GET me/meetings and the GET me meeting keys (default off).')->defaultFalse()->end()
                ->scalarNode('provider')->info('Service id implementing Meeting\\MeetingSourceInterface (required when enabled).')->defaultNull()->end()
                ->integerNode('max_range_days')->info('Longest from-to range.')->defaultValue(14)->min(1)->max(14)->end()
                ->integerNode('max_past_days')->info('How far back from may go (host retention: 7 days).')->defaultValue(1)->min(0)->max(7)->end()
                ->integerNode('default_limit')->defaultValue(100)->min(1)->max(200)->end()
                ->integerNode('refresh_after_seconds')->info('Default poll-gap hint to the clients.')->defaultValue(300)->min(60)->max(3600)->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('per_hour')->defaultValue(60)->min(1)->end()
                        ->integerNode('per_minute')->defaultValue(6)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('jitsi_hosts')
                    ->info('Extra self-hosted Jitsi hosts accepted as join links (host names only).')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $v) !== 1)
                            ->thenInvalid('meetings.jitsi_hosts takes lowercase host names only, got %s.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('links_calendars_route')->info('Host route name of Account → Integrations → Calendars (GET me links.calendars; https only).')->defaultNull()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['enabled'] && !is_string($v['provider']))
                ->thenInvalid('meetings.provider is required when the meetings API is enabled.')
            ->end();

        return $node;
    }
}
