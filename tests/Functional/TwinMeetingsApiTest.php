<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinMeetingSource;
use Nowo\TimeTrackBundle\Tests\Support\Ports\TwinPortConfig;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3, equivalence): every scenario of {@see MeetingsApiTest} again, with each stub host port replaced by
 * its framework-free twin ({@see TwinPortConfig}). The answers must be the same.
 */
final class TwinMeetingsApiTest extends MeetingsApiTest
{
    protected static function timeTrackConfig(): array
    {
        return TwinPortConfig::twin(parent::timeTrackConfig());
    }

    public function testTheScenariosRunThroughTheTwins(): void
    {
        $this->api('GET', '/api/time-track/me/meetings', null, $this->token());
        $twin = self::getContainer()->get(TwinMeetingSource::class);
        self::assertInstanceOf(TwinMeetingSource::class, $twin);
        self::assertGreaterThan(0, $twin->calls);
    }
}
