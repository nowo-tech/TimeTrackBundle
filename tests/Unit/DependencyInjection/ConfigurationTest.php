<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function sprintf;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
        ]]);

        self::assertSame('time_track_', $config['table_prefix']);
        self::assertFalse($config['clients']['enabled']);
        self::assertSame('/tools/time-track', $config['routes']['index']['path']);
        self::assertSame('/api/time-track/login', $config['clients']['routes']['login']['path']);
        self::assertArrayNotHasKey('templates', $config, 'removed in 2.0');
        self::assertNull($config['clients']['cors_allow_any_extension_origin']);
        self::assertNull($config['client_api']['allow_tauri_webview_origins']);
        self::assertNull($config['database']['private_entity_manager']);
        self::assertNull($config['security']['manager_can_edit_entries']);
        self::assertArrayNotHasKey('heartbeat', $config['clients']['routes']);
        self::assertNull($config['clients']['login_rate_limit']['max_attempts_per_ip']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function optionsWithoutEffect(): iterable
    {
        yield 'security.manager_can_edit_entries' => ['security', 'manager_can_edit_entries'];
        yield 'database.private_entity_manager' => ['database', 'private_entity_manager'];
        yield 'clients.cors_allow_any_extension_origin' => ['clients', 'cors_allow_any_extension_origin'];
        yield 'client_api.allow_tauri_webview_origins' => ['client_api', 'allow_tauri_webview_origins'];
    }

    /**
     * 2.0: the 1.x opt-outs stay accepted with the 2.0 value as deprecated no-ops (removed in 3.0); the other value
     * fails the container build (TimeTrackExtension).
     */
    #[DataProvider('optionsWithoutEffect')]
    public function testTheRemovedOptOutsAreDeprecatedNoOps(string $section, string $key): void
    {
        $root = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $root);
        $parent = $root->getChildren()[$section];
        self::assertInstanceOf(ArrayNode::class, $parent);
        $node = $parent->getChildren()[$key];
        self::assertInstanceOf(BaseNode::class, $node);

        self::assertTrue($node->isDeprecated());
        $deprecation = $node->getDeprecation($key, 'nowo_time_track.' . $section);
        self::assertSame('nowo-tech/time-track-bundle', $deprecation['package']);
        self::assertSame('2.0', $deprecation['version']);
        self::assertStringContainsString('"nowo_time_track.' . $section . '.' . $key . '" option has no effect since 2.0', $deprecation['message']);
    }

    public function testTheTemplatesKeysAreRemoved(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The nowo_time_track.templates keys (deprecated since 1.8) were removed in 2.0');

        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'templates' => ['css_framework' => 'tabler']]]);
    }

    public function testTheHeartbeatRouteIsRemoved(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The client API route "heartbeat" (POST /api/time-track/heartbeat, deprecated since 1.9) was removed in 2.0');

        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'clients' => ['routes' => ['heartbeat' => ['path' => '/hb']]]]]);
    }

    public function testWebUiDefaultsToTheUiKitLayoutAndTheBundlePages(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);

        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame(['index' => Configuration::DEFAULT_INDEX_TEMPLATE, 'reports' => Configuration::DEFAULT_REPORTS_TEMPLATE], $config['web_ui']['templates']);
    }

    public function testReportsPageSizeDefaultsTo50(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);

        self::assertSame(50, $config['reports']['page_size']);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function invalidPageSizeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'over the cap' => [501];
    }

    #[DataProvider('invalidPageSizeProvider')]
    public function testReportsPageSizeIsBounded(int $value): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'reports' => ['page_size' => $value]]]);
    }

    public function testMinSupportedVersionDefaultsToNullAndAcceptsSemver(): void
    {
        $defaults = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);
        self::assertSame(['provider' => null, 'extension' => null, 'desktop' => null, 'web' => null], $defaults['client_distribution']['min_supported_version']);

        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'client_distribution' => ['min_supported_version' => ['extension' => '1.4.0', 'desktop' => '2.0.0-rc.1+build.5', 'web' => '0.9.12']]]]);
        self::assertEquals(['provider' => null, 'extension' => '1.4.0', 'desktop' => '2.0.0-rc.1+build.5', 'web' => '0.9.12'], $config['client_distribution']['min_supported_version']);
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function invalidMinSupportedVersionProvider(): iterable
    {
        yield 'v prefix' => ['extension', 'v1.4.0'];
        yield 'two parts' => ['desktop', '1.4'];
        yield 'leading zero' => ['web', '01.4.0'];
        yield 'trailing text' => ['extension', '1.4.0 beta'];
        yield 'not a string' => ['desktop', 140];
    }

    #[DataProvider('invalidMinSupportedVersionProvider')]
    public function testMinSupportedVersionMustBeSemver(string $clientType, mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('client_distribution.min_supported_version.%s must be a semantic version', $clientType));

        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'client_distribution' => ['min_supported_version' => [$clientType => $value]]]]);
    }

    public function testClientApiDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);

        self::assertSame([], $config['client_api']['allowed_origins']);
        self::assertFalse($config['client_api']['allow_metadata']);
        self::assertFalse($config['client_api']['device_flow']['enabled']);
        self::assertSame(['cache_pool' => 'cache.app', 'per_token' => 60, 'per_ip' => 300, 'device_per_ip' => 30, 'window_seconds' => 60], $config['client_api']['rate_limit']);
        self::assertNull($config['client_distribution']['extension']['chrome']);
        self::assertSame('/api/time-track/timer/switch', $config['clients']['routes']['timer_switch']['path']);
        self::assertSame('nowo_time_track_device_verify', $config['routes']['device_verify']['name']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidClientApi(): iterable
    {
        yield 'wildcard origin' => [['allowed_origins' => ['chrome-extension://*']], 'exact origins only'];
        yield 'host page without uri' => [['device_flow' => ['enabled' => true, 'default_page' => false]], 'verification_uri is required'];
        yield 'plain http uri' => [['device_flow' => ['verification_uri' => 'http://host.example/device']], 'absolute https://'];
    }

    /**
     * @param array<string, mixed> $clientApi
     */
    #[DataProvider('invalidClientApi')]
    public function testInvalidClientApiIsRejected(array $clientApi, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'client_api' => $clientApi]]);
    }

    public function testLocalHttpVerificationUriIsAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'client_api' => ['device_flow' => ['verification_uri' => 'http://localhost:8000/device']]]]);

        self::assertSame('http://localhost:8000/device', $config['client_api']['device_flow']['verification_uri']);
    }
}
