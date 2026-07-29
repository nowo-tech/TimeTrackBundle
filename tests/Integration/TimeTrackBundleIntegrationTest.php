<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration;

use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TimeTrackBundleIntegrationTest extends TestCase
{
    public function testConfigurationAlias(): void
    {
        $alias = (new ReflectionClass(Configuration::class))->getConstant('ALIAS');

        self::assertSame('nowo_time_track', $alias);
    }
}
