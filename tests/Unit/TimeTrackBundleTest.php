<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit;

use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TimeTrackBundleTest extends TestCase
{
    public function testTranslationDomain(): void
    {
        $domain = (new ReflectionClass(TimeTrackBundle::class))->getConstant('TRANSLATION_DOMAIN');

        self::assertSame('NowoTimeTrackBundle', $domain);
    }

    public function testGetContainerExtension(): void
    {
        $bundle = new TimeTrackBundle();

        self::assertInstanceOf(TimeTrackExtension::class, $bundle->getContainerExtension());
    }

    public function testBuildRegistersCompilerPass(): void
    {
        $container = new ContainerBuilder();
        (new TimeTrackBundle())->build($container);

        self::assertNotEmpty($container->getCompilerPassConfig()->getPasses());
    }
}
