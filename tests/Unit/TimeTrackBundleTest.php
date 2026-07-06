<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit;

use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class TimeTrackBundleTest extends TestCase
{
    public function testTranslationDomain(): void
    {
        self::assertSame('NowoTimeTrackBundle', TimeTrackBundle::TRANSLATION_DOMAIN);
    }

    public function testGetContainerExtension(): void
    {
        $bundle = new TimeTrackBundle();

        self::assertInstanceOf(ExtensionInterface::class, $bundle->getContainerExtension());
        self::assertInstanceOf(TimeTrackExtension::class, $bundle->getContainerExtension());
    }

    public function testBuildRegistersCompilerPass(): void
    {
        $container = new ContainerBuilder();
        (new TimeTrackBundle())->build($container);

        self::assertNotEmpty($container->getCompilerPassConfig()->getPasses());
    }
}
