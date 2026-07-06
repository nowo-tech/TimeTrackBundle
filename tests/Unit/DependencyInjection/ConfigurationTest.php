<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\DependencyInjection;

use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
        ]]);

        self::assertSame('time_track_', $config['table_prefix']);
        self::assertFalse($config['clients']['enabled']);
        self::assertSame('/tools/time-track', $config['routes']['index']['path']);
        self::assertSame('/api/time-track/login', $config['clients']['routes']['login']['path']);
    }
}
