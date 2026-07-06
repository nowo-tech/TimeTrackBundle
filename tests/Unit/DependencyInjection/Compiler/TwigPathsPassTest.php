<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TwigPathsPassTest extends TestCase
{
    public function testAddsBundleViewsPath(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertNotEmpty($calls);
        self::assertSame('addPath', $calls[0][0]);
        self::assertSame('NowoTimeTrackBundle', $calls[0][1][1]);
    }

    public function testNoOpWhenLoaderMissing(): void
    {
        $container = new ContainerBuilder();

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    public function testPrependsApplicationOverrideWhenPresent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
        $projectDir  = sys_get_temp_dir() . '/time-track-project-' . uniqid('', true);
        $overrideDir = $projectDir . '/templates/bundles/NowoTimeTrackBundle';
        mkdir($overrideDir, 0777, true);
        $container->setParameter('kernel.project_dir', $projectDir);

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertSame('prependPath', $calls[0][0]);
        self::assertSame('NowoTimeTrackBundle', $calls[0][1][1]);
    }

    public function testResolvesNativeLoaderAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());
    }

    public function testUsesNativeLoaderDefinitionDirectly(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('twig.loader.native', new Definition('stdClass'));

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($container->getDefinition('twig.loader.native')->getMethodCalls());
    }

    public function testNoOpWhenAliasDoesNotResolveToDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setAlias('twig.loader.native', 'missing.loader');

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('missing.loader'));
    }

    public function testFollowsAliasChainUntilDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('real_loader', new Definition('stdClass'));
        $container->setAlias('alias2', 'real_loader');
        $container->setAlias('alias1', 'alias2');
        $container->setAlias('twig.loader.native', 'alias1');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($container->getDefinition('real_loader')->getMethodCalls());
    }
}
