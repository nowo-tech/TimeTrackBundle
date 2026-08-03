<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

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

    /**
     * @dataProvider acceptedCssFrameworkProvider
     */
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
}
