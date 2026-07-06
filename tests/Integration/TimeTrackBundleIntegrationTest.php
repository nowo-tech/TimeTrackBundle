<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Integration;

use Nowo\TimeTrackBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;

final class TimeTrackBundleIntegrationTest extends TestCase
{
    public function testConfigurationAlias(): void
    {
        self::assertSame('nowo_time_track', Configuration::ALIAS);
    }
}
