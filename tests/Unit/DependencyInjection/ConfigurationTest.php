<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use ErrorException;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\ErrorHandler\ErrorHandler;

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
        self::assertSame('@NowoTimeTrackBundle/layout.html.twig', $config['templates']['layout']);
        self::assertSame('tabler', $config['templates']['css_framework']);
        self::assertFalse($config['clients']['cors_allow_any_extension_origin']);
        self::assertNull($config['clients']['login_rate_limit']['max_attempts_per_ip']);
    }

    public function testManagerCanEditEntriesIsDeprecated(): void
    {
        $root = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $root);
        $security = $root->getChildren()['security'];
        self::assertInstanceOf(ArrayNode::class, $security);
        $node = $security->getChildren()['manager_can_edit_entries'];
        self::assertInstanceOf(BaseNode::class, $node);

        self::assertTrue($node->isDeprecated());
        $deprecation = $node->getDeprecation('manager_can_edit_entries', 'nowo_time_track.security');
        self::assertSame('nowo-tech/time-track-bundle', $deprecation['package']);
        self::assertSame('1.5', $deprecation['version']);
        self::assertStringContainsString('"nowo_time_track.security.manager_can_edit_entries" option is deprecated', $deprecation['message']);

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'security'   => ['manager_can_edit_entries' => false],
        ]]);
        self::assertFalse($config['security']['manager_can_edit_entries']);
    }

    public function testCustomLayoutAndCssFramework(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'templates'  => [
                'layout'        => 'base.html.twig',
                'css_framework' => 'bootstrap5',
            ],
        ]]);

        self::assertSame('base.html.twig', $config['templates']['layout']);
        self::assertSame('bootstrap5', $config['templates']['css_framework']);
    }

    public function testWebUiDefaultsToTheUiKitLayoutAndTheBundlePages(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);

        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame(['index' => Configuration::DEFAULT_INDEX_TEMPLATE, 'reports' => Configuration::DEFAULT_REPORTS_TEMPLATE], $config['web_ui']['templates']);
        self::assertSame(Configuration::LEGACY_LAYOUT, $config['templates']['layout']);
    }

    public function testDeprecatedTemplatesKeysFeedWebUi(): void
    {
        $config = @(new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'templates'  => ['layout' => 'base.html.twig', 'index' => 'host/timer.html.twig', 'reports' => 'host/reports.html.twig'],
        ]]);

        self::assertSame('base.html.twig', $config['web_ui']['layout_template']);
        self::assertSame(['index' => 'host/timer.html.twig', 'reports' => 'host/reports.html.twig'], $config['web_ui']['templates']);
        self::assertSame('base.html.twig', $config['templates']['layout'], 'still exposed (Twig global nowo_time_track_layout)');
    }

    public function testWebUiWinsOverTheDeprecatedKeysAndThe1xDefaultLayoutIsNotMapped(): void
    {
        $config = @(new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'templates'  => ['layout' => Configuration::LEGACY_LAYOUT, 'index' => 'old/timer.html.twig'],
            'web_ui'     => ['templates' => ['index' => 'new/timer.html.twig']],
        ]]);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame('new/timer.html.twig', $config['web_ui']['templates']['index']);

        $config = @(new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'templates'  => ['layout' => 'old/layout.html.twig'],
            'web_ui'     => ['layout_template' => null],
        ]]);
        self::assertNull($config['web_ui']['layout_template'], 'an explicit web_ui.layout_template (even null) wins');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function deprecatedTemplatesKeys(): iterable
    {
        yield 'layout' => ['layout', 'base.html.twig'];
        yield 'css_framework' => ['css_framework', 'tailwind'];
        yield 'index' => ['index', 'a.html.twig'];
        yield 'reports' => ['reports', 'b.html.twig'];
    }

    #[DataProvider('deprecatedTemplatesKeys')]
    public function testDeprecatedTemplatesKeysTriggerADeprecation(string $key, string $value): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('The "nowo_time_track.templates.' . $key . '" option is deprecated');

        ErrorHandler::call(static fn (): array => (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'templates'  => [$key => $value],
        ]]));
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

    #[DataProvider('acceptedCssFrameworkProvider')]
    public function testAcceptsCssFrameworkValues(string $value): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'templates'  => ['css_framework' => $value],
        ]]);

        self::assertSame($value, $config['templates']['css_framework']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedCssFrameworkProvider(): iterable
    {
        foreach (Configuration::CSS_FRAMEWORKS as $value) {
            yield $value => [$value];
        }
    }

    public function testRejectsInvalidCssFramework(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'templates'  => ['css_framework' => 'bulma'],
        ]]);
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
