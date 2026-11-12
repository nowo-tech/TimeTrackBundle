<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinDaysOffProfileSource;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinPortConfig;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3, equivalence): every scenario of {@see LeaveClientApiTest} again, with each stub host port replaced by
 * its framework-free twin ({@see TwinPortConfig}). The answers must be the same.
 */
final class TwinLeaveClientApiTest extends LeaveClientApiTest
{
    protected static function timeTrackConfig(): array
    {
        return TwinPortConfig::twin(parent::timeTrackConfig());
    }

    public function testTheScenariosRunThroughTheTwins(): void
    {
        $this->api('GET', '/api/time-track/me', null, $this->token(ClientScope::TimerRead));
        $twin = self::getContainer()->get(TwinDaysOffProfileSource::class);
        self::assertInstanceOf(TwinDaysOffProfileSource::class, $twin);
        self::assertGreaterThan(0, $twin->calls);
    }
}
