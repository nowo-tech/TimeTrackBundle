<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\ClientUserCheckerPass;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\User\UserCheckerInterface;

/**
 * 1.29 configuration: `client_api.prefix` (versioned routes, REQ-API-001) and `client_api.user_checker` (Beacon C5).
 */
final class ClientApiV129ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $api = $this->process([])['client_api'];

        self::assertSame('/api', $api['prefix']);
        self::assertSame(['enabled' => true, 'service' => null, 'firewall' => null, 'revoke_tokens' => true], $api['user_checker']);
    }

    public function testUserCheckerShorthands(): void
    {
        self::assertFalse($this->process(['user_checker' => false])['client_api']['user_checker']['enabled']);
        self::assertTrue($this->process(['user_checker' => true])['client_api']['user_checker']['enabled']);
        $service = $this->process(['user_checker' => 'app.user_checker'])['client_api']['user_checker'];
        self::assertSame([true, 'app.user_checker'], [$service['enabled'], $service['service']]);
    }

    public function testValidPrefixes(): void
    {
        self::assertSame('', $this->process(['prefix' => ''])['client_api']['prefix']);
        self::assertSame('/edge/api', $this->process(['prefix' => '/edge/api'])['client_api']['prefix']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPrefixes(): iterable
    {
        yield 'no leading slash' => ['api'];
        yield 'trailing slash' => ['/api/'];
        yield 'whitespace' => ['/my api'];
        yield 'not a string' => [42];
    }

    #[DataProvider('invalidPrefixes')]
    public function testInvalidPrefixesFail(mixed $prefix): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['prefix' => $prefix]);
    }

    public function testTheExtensionWiresTheDefaultChecker(): void
    {
        $container = $this->load([]);

        self::assertSame('/api', $container->getParameter('nowo_time_track.client_api.prefix'));
        self::assertSame('/api/time-track/v1', $container->getParameter('nowo_time_track.client_api.v1_path_prefix'));
        $checker = $container->getDefinition(ClientAccountChecker::class);
        self::assertSame(UserCheckerInterface::class, (string) $checker->getArgument('$userChecker'));
        self::assertSame([['firewall' => null]], $checker->getTag(ClientUserCheckerPass::TAG));
        self::assertSame(ClientTokenRepositoryInterface::class, (string) $checker->getArgument('$tokens'));
        self::assertTrue($checker->getArgument('$revokeTokens'));
        $guardArgument = $container->getDefinition(ClientApiGuard::class)->getArgument('$accountChecker');
        self::assertInstanceOf(Reference::class, $guardArgument);
        self::assertSame(ClientAccountChecker::class, (string) $guardArgument);
    }

    public function testTheExtensionWiresTheOptions(): void
    {
        $container = $this->load(['prefix' => '', 'user_checker' => ['service' => 'app.user_checker', 'revoke_tokens' => false]], '/t');
        self::assertSame('/t/time-track/v1', $container->getParameter('nowo_time_track.client_api.v1_path_prefix'));
        $checker = $container->getDefinition(ClientAccountChecker::class);
        self::assertSame('app.user_checker', (string) $checker->getArgument('$userChecker'));
        self::assertFalse($checker->hasTag(ClientUserCheckerPass::TAG));
        self::assertFalse($checker->getArgument('$revokeTokens'));

        $firewall = $this->load(['user_checker' => ['firewall' => 'api']])->getDefinition(ClientAccountChecker::class);
        self::assertSame([['firewall' => 'api']], $firewall->getTag(ClientUserCheckerPass::TAG));

        $off = $this->load(['user_checker' => false])->getDefinition(ClientAccountChecker::class);
        self::assertNull($off->getArgument('$userChecker'));
        self::assertFalse($off->hasTag(ClientUserCheckerPass::TAG));
    }

    /**
     * @param array<string, mixed> $clientApi
     */
    private function load(array $clientApi, string $routePrefix = ''): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TimeTrackExtension())->load([[
            'user_class'   => User::class,
            'route_prefix' => $routePrefix,
            'clients'      => ['enabled' => true],
            'client_api'   => $clientApi,
        ]], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $clientApi
     *
     * @return array<string, mixed>
     */
    private function process(array $clientApi): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
            'client_api' => $clientApi,
        ]]);
    }
}
