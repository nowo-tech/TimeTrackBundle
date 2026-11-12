<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinPortConfig;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinWorkdaySource;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3, equivalence): every scenario of {@see WorkingTimeClientApiTest} again, with each stub host port replaced by
 * its framework-free twin ({@see TwinPortConfig}). The answers must be the same.
 */
final class TwinWorkingTimeClientApiTest extends WorkingTimeClientApiTest
{
    protected static function timeTrackConfig(): array
    {
        return TwinPortConfig::twin(parent::timeTrackConfig());
    }

    public function testTheScenariosRunThroughTheTwins(): void
    {
        $this->api('GET', '/api/time-track/workday', null, $this->token(ClientScope::WorkdayRead));
        $twin = self::getContainer()->get(TwinWorkdaySource::class);
        self::assertInstanceOf(TwinWorkdaySource::class, $twin);
        self::assertGreaterThan(0, $twin->calls);
    }
}
