<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\TimeTrackBundle\Bridge\StubTaskProvider;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\DefaultClientAuthenticator;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Twig\TimeTrackTwigExtension;
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
        self::assertSame(
            '@NowoTimeTrackBundle/layout.html.twig',
            $container->getParameter('nowo_time_track.templates.layout'),
        );
        self::assertTrue($container->hasAlias(TaskProviderInterface::class));
        self::assertTrue($container->hasAlias(TeamContextProviderInterface::class));
        self::assertTrue($container->hasDefinition(StubTaskProvider::class));
        self::assertTrue($container->hasDefinition(TimeTrackTwigExtension::class));
        self::assertSame(
            '@NowoTimeTrackBundle/layout.html.twig',
            $container->getDefinition(TimeTrackTwigExtension::class)->getArgument('$layoutTemplate'),
        );
        self::assertSame('tabler', $container->getParameter('nowo_time_track.templates.css_framework'));
        self::assertSame(
            'tabler',
            $container->getDefinition(TimeTrackTwigExtension::class)->getArgument('$cssFramework'),
        );
    }

    public function testLoadWiresCustomLayoutIntoTwigGlobalArgument(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new TimeTrackExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'templates'  => ['layout' => 'base.html.twig'],
        ]], $container);

        self::assertSame('base.html.twig', $container->getParameter('nowo_time_track.templates.layout'));
        self::assertSame(
            'base.html.twig',
            $container->getDefinition(TimeTrackTwigExtension::class)->getArgument('$layoutTemplate'),
        );
        self::assertSame('base.html.twig', $container->getParameter('nowo_time_track.templates')['layout']);
    }

    public function testLoadWiresCustomCssFrameworkIntoTwigGlobalArgument(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new TimeTrackExtension())->load([[
            'user_class' => 'App\\Entity\\User',
            'templates'  => ['css_framework' => 'custom'],
        ]], $container);

        self::assertSame('custom', $container->getParameter('nowo_time_track.templates.css_framework'));
        self::assertSame(
            'custom',
            $container->getDefinition(TimeTrackTwigExtension::class)->getArgument('$cssFramework'),
        );
        self::assertSame('custom', $container->getParameter('nowo_time_track.templates')['css_framework']);
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

        self::assertTrue($container->getDefinition(DefaultClientAuthenticator::class)->isAutowired());
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

        $definition = $container->getDefinition(DefaultClientAuthenticator::class);
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
