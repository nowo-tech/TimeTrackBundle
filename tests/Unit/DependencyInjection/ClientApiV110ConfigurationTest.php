<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Client\ConfigClientApiSettingsProvider;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsResolver;
use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.10 configuration: `client_api.backdate`, `idle`, `idle_check`, `task_url` (defaults, ranges, wiring).
 */
final class ClientApiV110ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $api = $this->process([])['client_api'];

        self::assertSame(['max_seconds' => 86400, 'clock_skew_seconds' => 120], $api['backdate']);
        self::assertSame(['allow_reassign' => true], $api['idle']);
        self::assertSame(['enabled' => true, 'threshold_minutes' => 10, 'answer_timeout_minutes' => 5, 'provider' => null], $api['idle_check']);
        self::assertSame(['origin' => null], $api['task_url']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'backdate above 24 h' => [['backdate' => ['max_seconds' => 86401]]];
        yield 'negative backdate' => [['backdate' => ['max_seconds' => -1]]];
        yield 'skew above 900' => [['backdate' => ['clock_skew_seconds' => 901]]];
        yield 'threshold below 5' => [['idle_check' => ['threshold_minutes' => 4]]];
        yield 'threshold above 60' => [['idle_check' => ['threshold_minutes' => 61]]];
        yield 'timeout below 1' => [['idle_check' => ['answer_timeout_minutes' => 0]]];
        yield 'timeout above 30' => [['idle_check' => ['answer_timeout_minutes' => 31]]];
        yield 'http origin' => [['task_url' => ['origin' => 'http://beacon.test']]];
        yield 'origin with path' => [['task_url' => ['origin' => 'https://beacon.test/app']]];
        yield 'origin with trailing slash' => [['task_url' => ['origin' => 'https://beacon.test/']]];
        yield 'wildcard origin' => [['task_url' => ['origin' => 'https://*.beacon.test']]];
        yield 'origin with credentials' => [['task_url' => ['origin' => 'https://u:p@beacon.test']]];
        yield 'origin with whitespace' => [['task_url' => ['origin' => 'https://beacon.test ']]];
        yield 'non-string origin' => [['task_url' => ['origin' => 42]]];
        yield 'trailing dot' => [['task_url' => ['origin' => 'https://beacon.test.']]];
        yield 'bare dot' => [['task_url' => ['origin' => 'https://.']]];
        yield 'empty label' => [['task_url' => ['origin' => 'https://beacon..test']]];
        yield 'leading hyphen' => [['task_url' => ['origin' => 'https://-beacon.test']]];
        yield 'port above 65535' => [['task_url' => ['origin' => 'https://beacon.test:65536']]];
        yield 'port 0' => [['task_url' => ['origin' => 'https://beacon.test:0']]];
        yield 'port with leading zero' => [['task_url' => ['origin' => 'https://beacon.test:0443']]];
        yield 'empty port' => [['task_url' => ['origin' => 'https://beacon.test:']]];
    }

    /**
     * @param array<string, mixed> $clientApi
     */
    #[DataProvider('invalid')]
    public function testOutOfRangeValuesFail(array $clientApi): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process($clientApi);
    }

    public function testValidOrigins(): void
    {
        self::assertSame('https://beacon.example.com', $this->process(['task_url' => ['origin' => 'https://beacon.example.com']])['client_api']['task_url']['origin']);
        self::assertSame('https://localhost:8443', $this->process(['task_url' => ['origin' => 'https://localhost:8443']])['client_api']['task_url']['origin']);
        self::assertSame('https://my-beacon.example.co:65535', $this->process(['task_url' => ['origin' => 'https://my-beacon.example.co:65535']])['client_api']['task_url']['origin']);
        self::assertSame('https://b:1', $this->process(['task_url' => ['origin' => 'https://b:1']])['client_api']['task_url']['origin']);
    }

    public function testExtensionWiresTheNewSettings(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([[
            'user_class' => User::class,
            'clients'    => ['enabled' => true],
            'client_api' => [
                'backdate'   => ['max_seconds' => 3600, 'clock_skew_seconds' => 60],
                'idle'       => ['allow_reassign' => false],
                'idle_check' => ['enabled' => false, 'threshold_minutes' => 15, 'answer_timeout_minutes' => 3, 'provider' => 'app.idle_check'],
                'task_url'   => ['origin' => 'https://beacon.example.com'],
            ],
        ]], $container);

        $settings = $container->getDefinition(ConfigClientApiSettingsProvider::class);
        self::assertSame(3600, $settings->getArgument('$backdateMaxSeconds'));
        self::assertSame(60, $settings->getArgument('$clockSkewSeconds'));
        self::assertFalse($settings->getArgument('$allowIdleReassign'));
        self::assertFalse($settings->getArgument('$idleCheckEnabled'));
        self::assertSame(15, $settings->getArgument('$idleCheckThresholdMinutes'));
        self::assertSame(3, $settings->getArgument('$idleCheckAnswerTimeoutMinutes'));

        self::assertSame('https://beacon.example.com', $container->getDefinition(ClientTaskPresenter::class)->getArgument('$taskUrlOrigin'));
        $provider = $container->getDefinition(IdleCheckSettingsResolver::class)->getArgument('$provider');
        self::assertInstanceOf(Reference::class, $provider);
        self::assertSame('app.idle_check', (string) $provider);

        $controller = $container->getDefinition(TimeTrackClientApiController::class);
        self::assertSame(ClientTaskPresenter::class, (string) $controller->getArgument('$taskPresenter'));
        self::assertSame(IdleCheckSettingsResolver::class, (string) $controller->getArgument('$idleCheckResolver'));
    }

    public function testNoIdleCheckProviderByDefault(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([['user_class' => User::class]], $container);

        self::assertNull($container->getDefinition(IdleCheckSettingsResolver::class)->getArgument('$provider'));
        self::assertNull($container->getDefinition(ClientTaskPresenter::class)->getArgument('$taskUrlOrigin'));
    }

    /**
     * @param array<string, mixed> $clientApi
     *
     * @return array<string, mixed>
     */
    private function process(array $clientApi): array
    {
        /** @var array<string, mixed> $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'client_api' => $clientApi]]);

        return $config;
    }
}
