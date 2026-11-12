<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TimeTrackBundle\Calendar\CalendarOAuthClients;
use Nowo\TimeTrackBundle\Integration\TimeEntryBillableDefaultProviderInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Tests\Fixtures\DddHost\HostUser;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\HostFlushListener;
use Nowo\TimeTrackBundle\Tests\Stub\LeaveDaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\MutableCalendarOAuthAppProvider;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\PsrOnlyClock;
use Nowo\TimeTrackBundle\Tests\Stub\StubBillableDefaultProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubLeaveProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinClientPorts;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinPortConfig;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function array_replace_recursive;
use function class_exists;
use function dirname;
use function getenv;
use function is_string;
use function md5;
use function serialize;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host application of the functional tests (REQ-TEST-003): Doctrine on in-memory SQLite, SecurityBundle with an entity
 * user provider, the bundle routes imported through the route loader, the client API enabled, FormKit and UiKit.
 * `$timeTrackConfig` is merged into the `nowo_time_track` configuration (one cache directory per variant).
 *
 * Since 1.19.1, `$psrOnlyClock`: `Psr\Clock\ClockInterface` points to {@see PsrOnlyClock} (a host's own PSR-20 clock,
 * not a Symfony clock), and every bundle service whose constructor takes a clock is made public and listed in the
 * `time_track_test.clock_consumers` parameter so a test can build each one.
 */
final class FunctionalKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $timeTrackConfig
     */
    public function __construct(private readonly array $timeTrackConfig = [], private readonly bool $plainEntryStore = false, private readonly bool $xmlHostUser = false, bool $debug = true, private readonly bool $psrOnlyClock = false)
    {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new SecurityBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new TimeTrackBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->varDir() . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir() . '/log';
    }

    private function varDir(): string
    {
        return sys_get_temp_dir() . '/nowo_time_track_functional/' . md5(serialize($this->timeTrackConfig) . serialize(self::privateEntityManager()) . ($this->plainEntryStore ? 'plain' : '') . ($this->xmlHostUser ? 'xml-host-user' : '') . ($this->debug ? '' : 'no-debug') . ($this->psrOnlyClock ? 'psr-only-clock' : '') . PHP_VERSION_ID . self::databaseUrl());
    }

    /**
     * Since 1.13: the test database (`TIME_TRACK_TEST_DATABASE_URL`, e.g. a MySQL or PostgreSQL container for the
     * migration and lock tests), in-memory SQLite by default.
     */
    public static function databaseUrl(): string
    {
        $url = getenv('TIME_TRACK_TEST_DATABASE_URL');

        return is_string($url) && $url !== '' ? $url : 'sqlite:///:memory:';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'functional-test',
            'test'                 => true,
            'http_method_override' => false,
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'property_info'        => ['with_constructor_extractor' => true],
            'translator'           => ['default_path' => '%kernel.project_dir%/tests/Fixtures/translations', 'fallbacks' => ['en']],
            'default_locale'       => 'en',
            // Login throttle counters: a filesystem pool (an array pool is reset between requests), cleared per test.
            'cache' => ['pools' => ['cache.time_track_test' => ['adapter' => 'cache.adapter.filesystem', 'public' => true]]],
            // Since 1.14: the calendar adapters never reach the network in tests.
            'http_client' => ['mock_response_factory' => NoNetworkHttpResponses::class],
        ]);
        $container->services()->set(NoNetworkHttpResponses::class)->public();
        if ($this->plainEntryStore) {
            // Since 1.15: a host's replacement store with no optional capability.
            $container->services()->set(TimeEntryRepositoryInterface::class, InMemoryTimeEntryRepository::class)->public();
        }
        // Since 1.16: host Doctrine listeners a test switches on (off by default).
        $container->services()->set(HostFlushListener::class)->public()
            ->tag('doctrine.event_listener', ['event' => 'onFlush', 'priority' => 2000])
            ->tag('doctrine.event_listener', ['event' => 'postFlush', 'priority' => 100]);
        // Since 1.14: a recording logger (public) so tests can scan log records for personal data.
        $container->services()->set('logger', RecordingLogger::class)->public();
        // Since 1.13: approver provider of the timesheet variants (public: tests configure it per case).
        $container->services()->set(StubTimesheetApproverProvider::class)->public();
        $container->services()->set(FixedScopeProvider::class)->public();
        $container->services()->set(FixedWorkingCalendar::class)->args(['UTC'])->public();
        $container->services()->set(StubWorkdayProvider::class)->public();
        // Since 1.22 (spec 204): the host's leave adapter (used only where a test sets `client_api.leave_provider`).
        $container->services()->set(StubLeaveProvider::class)->public();
        $container->services()->set(LeaveDaysOffProfileProvider::class)->public();
        // Since 1.14: calendar fakes (no real calendar, token or event ever).
        $container->services()->set(StubTaskKeyResolver::class)->public();
        $container->services()->set(StubCalendarCredentialProvider::class)->public();
        $container->services()->set(MutableProfileProvider::class)->public();
        $container->services()->set(StubMeetingProvider::class)->public();
        // Since 1.20: a runtime OAuth app store (`calendar.oauth.app_provider` in the worker test) and the discovery
        // service, public so a test can reach them.
        $container->services()->set(MutableCalendarOAuthAppProvider::class)->public();
        $container->services()->alias('time_track_test.calendar_oauth_clients', CalendarOAuthClients::class)->public();
        $container->services()->set(RacingCachePool::class)->public();
        // Since 1.23.0 (REQ-ARCH-001 rule 3): the framework-free twins of the stub ports, sharing the stubs' state
        // (used where a test runs its scenarios through TwinPortConfig::twin()).
        $container->services()->set(TwinClientPorts::class)->public();
        foreach (TwinPortConfig::TWINS as $stub => $twin) {
            $container->services()->set($twin)->args([service($stub)])->public();
        }
        // Since 1.21: the host's billable default (answers null, the default behaviour, until a test sets an answer).
        $container->services()->set(StubBillableDefaultProvider::class)->args([null])->public();
        $container->services()->alias(TimeEntryBillableDefaultProviderInterface::class, StubBillableDefaultProvider::class);
        if ($this->psrOnlyClock) {
            // Since 1.19.1: the host's own PSR clock wins over the framework's `clock` alias (as in Beacon).
            $container->services()->set(PsrOnlyClock::class)->public();
            $container->services()->alias(ClockInterface::class, PsrOnlyClock::class)->public();
        }
        $container->extension('twig', [
            'paths' => [__DIR__ . '/../Fixtures/templates' => 'TimeTrackTest'],
        ]);
        $container->extension('doctrine', [
            'dbal' => ['url' => self::databaseUrl()],
            'orm'  => [
                'auto_mapping' => true,
                'mappings'     => ['FunctionalFixtures' => [
                    'type'      => 'attribute',
                    'is_bundle' => false,
                    'dir'       => __DIR__ . '/../Fixtures/FunctionalEntity',
                    'prefix'    => 'Nowo\\TimeTrackBundle\\Tests\\Fixtures\\FunctionalEntity',
                ]] + ($this->xmlHostUser ? ['DddHost' => [
                    // Since 1.19 (REQ-ARCH-001): a DDD host's user_class, plain PHP mapped in XML.
                    'type'      => 'xml',
                    'is_bundle' => false,
                    'dir'       => __DIR__ . '/../Fixtures/DddHost/doctrine',
                    'prefix'    => 'Nowo\\TimeTrackBundle\\Tests\\Fixtures\\DddHost',
                ]] : []),
            ] + $this->nativeLazyObjects(),
        ]);
        $container->extension('security', [
            'password_hashers' => [AppUser::class => 'plaintext'],
            'providers'        => ['app_users' => ['entity' => ['class' => AppUser::class, 'property' => 'email']]],
            'firewalls'        => [
                'time_track_api' => ['pattern' => '^/api/time-track', 'security' => false],
                'main'           => ['lazy' => true, 'provider' => 'app_users'],
            ],
        ]);
        $container->extension('nowo_time_track', array_replace_recursive([
            'user_class' => $this->xmlHostUser ? HostUser::class : AppUser::class,
            'clients'    => [
                'enabled'              => true,
                'user_provider'        => 'security.user.provider.concrete.app_users',
                'cors_allowed_origins' => ['chrome-extension://functional'],
                'login_rate_limit'     => ['max_attempts' => 2, 'cache_pool' => 'cache.time_track_test'],
            ],
            'client_api' => ['rate_limit' => ['cache_pool' => 'cache.time_track_test']],
        ], self::privateEntityManager(), $this->timeTrackConfig));
    }

    /**
     * Since 1.23.0: `TIME_TRACK_TEST_PRIVATE_ENTITY_MANAGER=1` runs the suite with `database.private_entity_manager: true`
     * (CI job "Functional suite - private entity manager"); a test's own configuration still wins.
     *
     * @return array<string, mixed>
     */
    public static function privateEntityManager(): array
    {
        $database = getenv('TIME_TRACK_TEST_PRIVATE_ENTITY_MANAGER') === '1' ? ['private_entity_manager' => true] : [];
        // Since 1.23.0: `TIME_TRACK_TEST_MAPPING_TYPE=xml` runs the suite on the shipped XML mapping (REQ-ARCH-001 rule 2).
        if (getenv('TIME_TRACK_TEST_MAPPING_TYPE') === 'xml') {
            $database['mapping_type'] = 'xml';
        }

        return $database === [] ? [] : ['database' => $database];
    }

    /**
     * Since 1.19.1 (`$psrOnlyClock` only): makes every bundle service whose constructor takes a clock public, so it is
     * neither inlined nor removed and a test can build it, and lists their ids in `time_track_test.clock_consumers`.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$this->psrOnlyClock) {
            return;
        }
        $consumers = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if ($definition->isAbstract() || $definition->isSynthetic() || !str_starts_with($class, 'Nowo\\TimeTrackBundle\\') || !class_exists($class)) {
                continue;
            }
            $constructor = (new ReflectionClass($class))->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                if ($this->namesAClock($parameter->getType())) {
                    $definition->setPublic(true);
                    $consumers[] = $id;

                    break;
                }
            }
        }
        sort($consumers);
        $container->setParameter('time_track_test.clock_consumers', $consumers);
    }

    private function namesAClock(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->namesAClock($member)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof ReflectionNamedType && str_ends_with($type->getName(), '\\ClockInterface');
    }

    /**
     * ORM 3 on PHP 8.4+ needs native lazy objects (see HostRoutingKernel).
     *
     * @return array<string, bool>
     */
    private function nativeLazyObjects(): array
    {
        if (PHP_VERSION_ID < 80400) {
            return [];
        }

        $root = (new DoctrineConfiguration(false))->getConfigTreeBuilder()->buildTree();
        $orm  = $root instanceof ArrayNode ? ($root->getChildren()['orm'] ?? null) : null;
        $node = $orm instanceof ArrayNode ? ($orm->getChildren()['enable_native_lazy_objects'] ?? null) : null;

        return $node instanceof BaseNode && !$node->isDeprecated() ? ['enable_native_lazy_objects' => true] : [];
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'nowo_time_track');
    }
}
