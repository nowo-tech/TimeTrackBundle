<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TimeTrackExtensionTest extends TestCase
{
    public function testLoadSetsParametersAndAliases(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        $extension = new TimeTrackExtension();
        $extension->load([[
            'user_class' => 'App\\Entity\\User',
            'clients'    => ['enabled' => true],
        ]], $container);

        self::assertSame('App\\Entity\\User', $container->getParameter('nowo_time_track.user_class'));
        self::assertTrue($container->getParameter('nowo_time_track.clients.enabled'));
        self::assertTrue($container->hasAlias(TaskProviderInterface::class));
        self::assertTrue($container->hasAlias(TeamContextProviderInterface::class));
        self::assertTrue($container->hasDefinition(StubTaskProvider::class));
    }

    public function testPrependRegistersDoctrineMapping(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());

        $extension = new TimeTrackExtension();
        $extension->prepend($container);

        $configs = $container->getExtensionConfig('doctrine');
        self::assertNotEmpty($configs);
        self::assertArrayHasKey('orm', $configs[0]);
    }

    public function testPrependNoOpWithoutDoctrineExtension(): void
    {
        $container = new ContainerBuilder();

        (new TimeTrackExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_time_track', (new TimeTrackExtension())->getAlias());
    }

    public function testLoadWithEmptyTaskProviderFallsBackToStub(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        $extension = new TimeTrackExtension();
        $extension->load([[
            'user_class'    => 'App\\Entity\\User',
            'task_provider' => '',
        ]], $container);

        self::assertTrue($container->hasDefinition(StubTaskProvider::class));
    }

    public function testLoadSkipsStubDefinitionWhenAlreadyRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setDefinition(StubTaskProvider::class, new Definition(StubTaskProvider::class));

        (new TimeTrackExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        self::assertCount(1, array_filter(
            array_keys($container->getDefinitions()),
            static fn (string $id): bool => $id === StubTaskProvider::class,
        ));
    }

    public function testLoadWithCustomTeamContextProvider(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setDefinition('app.team_context', new Definition(stdClass::class));

        (new TimeTrackExtension())->load([[
            'user_class'            => 'App\\Entity\\User',
            'team_context_provider' => 'app.team_context',
        ]], $container);

        self::assertSame('app.team_context', (string) $container->getAlias(TeamContextProviderInterface::class));
    }

    public function testLoadRegistersDefaultAuthenticatorWithAutowire(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new TimeTrackExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'clients'    => ['enabled' => true],
        ]], $container);

        self::assertTrue($container->getDefinition(\Nowo\TimeTrackBundle\Client\DefaultClientAuthenticator::class)->isAutowired());
    }

    public function testLoadRegistersDefaultAuthenticatorWithUserProvider(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new TimeTrackExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'clients'    => [
                'enabled'       => true,
                'user_provider' => 'security.user.provider.concrete.users',
            ],
        ]], $container);

        $definition = $container->getDefinition(\Nowo\TimeTrackBundle\Client\DefaultClientAuthenticator::class);
        self::assertFalse($definition->isAutowired());
        self::assertArrayHasKey('$userProvider', $definition->getArguments());
    }

    public function testLoadWithCustomProvidersAndAuthenticator(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setDefinition('app.task_provider', new Definition(StubTaskProvider::class));
        $container->setDefinition('app.authenticator', new Definition(stdClass::class));

        $extension = new TimeTrackExtension();
        $extension->load([[
            'user_class'            => 'App\\Entity\\User',
            'task_provider'         => 'app.task_provider',
            'team_context_provider' => '',
            'clients'               => [
                'enabled'       => true,
                'authenticator' => 'app.authenticator',
                'user_provider' => 'security.user.provider.concrete.users',
            ],
        ]], $container);

        self::assertTrue($container->hasAlias(TaskProviderInterface::class));
        self::assertSame('app.authenticator', (string) $container->getAlias(ClientAuthenticatorInterface::class));
    }
}
