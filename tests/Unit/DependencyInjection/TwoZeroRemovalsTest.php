<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 2.0 (REQ-BC-001, REQ-ARCH-001): every configuration that relied on a removed 1.x behaviour fails the container build
 * with a message that names the fix and UPGRADING "To 2.0.0", instead of falling back silently. The value that matches
 * the 2.0 behaviour stays accepted (a deprecated no-op, removed in 3.0).
 */
final class TwoZeroRemovalsTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function removedConfigurations(): iterable
    {
        yield 'private_entity_manager: false' => [['database' => ['private_entity_manager' => false]], TimeTrackExtension::PRIVATE_ENTITY_MANAGER_REMOVED];
        yield '"*" in cors_allowed_origins' => [['clients' => ['cors_allowed_origins' => ['chrome-extension://abc', '*']]], TimeTrackExtension::CORS_WILDCARD_REMOVED];
        yield '"*" with the client API off' => [['clients' => ['enabled' => false, 'cors_allowed_origins' => ['*']]], TimeTrackExtension::CORS_WILDCARD_REMOVED];
        yield 'cors_allow_any_extension_origin: true' => [['clients' => ['cors_allow_any_extension_origin' => true]], TimeTrackExtension::CORS_ANY_EXTENSION_REMOVED];
        yield 'allow_tauri_webview_origins: true' => [['client_api' => ['allow_tauri_webview_origins' => true]], TimeTrackExtension::TAURI_WEBVIEW_ORIGINS_REMOVED];
        yield 'manager_can_edit_entries: false' => [['security' => ['manager_can_edit_entries' => false]], Configuration::MANAGER_CAN_EDIT_ENTRIES_REMOVED];
        yield 'heartbeat in routes.include' => [['clients' => ['enabled' => true], 'client_api' => ['routes' => ['include' => ['login', 'logout', 'heartbeat']]]], Configuration::HEARTBEAT_REMOVED];
        yield 'a heartbeat route' => [['clients' => ['routes' => ['heartbeat' => ['path' => '/hb']]]], Configuration::HEARTBEAT_REMOVED];
        yield 'templates.layout' => [['templates' => ['layout' => 'base.html.twig']], Configuration::TEMPLATES_REMOVED];
        yield 'templates.css_framework' => [['templates' => ['css_framework' => 'tabler']], Configuration::TEMPLATES_REMOVED];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('removedConfigurations')]
    public function testARemovedConfigurationFailsTheBuild(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        @$this->load($config);
    }

    public function testEveryMessageNamesUpgrading(): void
    {
        foreach ([TimeTrackExtension::PRIVATE_ENTITY_MANAGER_REMOVED, TimeTrackExtension::CORS_WILDCARD_REMOVED, TimeTrackExtension::CORS_ANY_EXTENSION_REMOVED, TimeTrackExtension::TAURI_WEBVIEW_ORIGINS_REMOVED, Configuration::MANAGER_CAN_EDIT_ENTRIES_REMOVED, Configuration::HEARTBEAT_REMOVED, Configuration::TEMPLATES_REMOVED] as $message) {
            self::assertStringContainsString('UPGRADING "To 2.0.0"', $message);
        }
    }

    public function testTheValuesOfThe20BehaviourAreAcceptedNoOps(): void
    {
        $container = @$this->load([
            'database'   => ['private_entity_manager' => true],
            'security'   => ['manager_can_edit_entries' => true],
            'clients'    => ['enabled' => true, 'cors_allow_any_extension_origin' => false, 'cors_allowed_origins' => ['chrome-extension://abc']],
            'client_api' => ['allow_tauri_webview_origins' => false, 'routes' => ['include' => ['login', 'logout', 'entries']]],
        ]);

        self::assertSame(['login', 'logout', 'entries'], $container->getParameter('nowo_time_track.client_api.routes.include'));
        self::assertTrue($container->getDefinition(TeamAccessGuard::class)->getArgument('$managerCanEditEntries'));
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
