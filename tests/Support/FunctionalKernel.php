<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function array_replace_recursive;
use function dirname;
use function getenv;
use function is_string;
use function md5;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host application of the functional tests (REQ-TEST-003): Doctrine on in-memory SQLite, SecurityBundle with an entity
 * user provider, the bundle routes imported through the route loader, the client API enabled, FormKit and UiKit.
 * `$timeTrackConfig` is merged into the `nowo_time_track` configuration (one cache directory per variant).
 */
final class FunctionalKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $timeTrackConfig
     */
    public function __construct(private readonly array $timeTrackConfig = [])
    {
        parent::__construct('test', true);
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
        return sys_get_temp_dir() . '/nowo_time_track_functional/' . md5(serialize($this->timeTrackConfig) . PHP_VERSION_ID . self::databaseUrl());
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
        // Since 1.14: a recording logger (public) so tests can scan log records for personal data.
        $container->services()->set('logger', RecordingLogger::class)->public();
        // Since 1.13: approver provider of the timesheet variants (public: tests configure it per case).
        $container->services()->set(StubTimesheetApproverProvider::class)->public();
        $container->services()->set(FixedScopeProvider::class)->public();
        $container->services()->set(FixedWorkingCalendar::class)->args(['UTC'])->public();
        $container->services()->set(StubWorkdayProvider::class)->public();
        // Since 1.14: calendar fakes (no real calendar, token or event ever).
        $container->services()->set(StubTaskKeyResolver::class)->public();
        $container->services()->set(StubCalendarCredentialProvider::class)->public();
        $container->services()->set(MutableProfileProvider::class)->public();
        $container->services()->set(StubMeetingProvider::class)->public();
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
                ]],
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
            'user_class' => AppUser::class,
            'clients'    => [
                'enabled'              => true,
                'user_provider'        => 'security.user.provider.concrete.app_users',
                'cors_allowed_origins' => ['chrome-extension://functional'],
                'login_rate_limit'     => ['max_attempts' => 2, 'cache_pool' => 'cache.time_track_test'],
            ],
            'client_api' => ['rate_limit' => ['cache_pool' => 'cache.time_track_test']],
        ], $this->timeTrackConfig));
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
