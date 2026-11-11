<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Functional;

use Nowo\TimeTrackBundle\Calendar\CalendarHttpClient;
use Nowo\TimeTrackBundle\Calendar\CalendarTokenEndpoint;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\PsrOnlyClock;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;
use Nowo\TimeTrackBundle\Tests\Support\FunctionalKernel;
use Psr\Clock\ClockInterface;
use ReflectionObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function sprintf;

/**
 * Since 1.19.1 (Beacon report 2026-11-11): a host whose `Psr\Clock\ClockInterface` is its own PSR-20 clock — not a
 * `Symfony\Component\Clock\ClockInterface` (Beacon: Cookie Consent's `SystemClock`) — builds every bundle service that
 * takes a clock, with every feature on. 1.19.0 failed on {@see CalendarTokenEndpoint} and {@see CalendarHttpClient}.
 * The debug kernel also runs the framework's type-declaration check on every (now public) clock consumer.
 */
final class PsrOnlyClockContainerTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new FunctionalKernel([
            'timesheets'     => ['enabled' => true, 'scope_provider' => FixedScopeProvider::class, 'approver_provider' => StubTimesheetApproverProvider::class],
            'working_time'   => ['enabled' => true, 'calendar_provider' => FixedWorkingCalendar::class],
            'calendar'       => ['enabled' => true, 'task_key_resolver' => StubTaskKeyResolver::class, 'credential_provider' => StubCalendarCredentialProvider::class],
            'meetings'       => ['enabled' => true, 'provider' => StubMeetingProvider::class],
            'manual_entries' => ['enabled' => true],
            'client_api'     => ['profile_provider' => MutableProfileProvider::class, 'workday_provider' => StubWorkdayProvider::class],
        ], false, false, true, true);
    }

    public function testEveryClockConsumerIsBuiltWithTheHostPsrClock(): void
    {
        $container = self::getContainer();
        $hostClock = $container->get(PsrOnlyClock::class);
        self::assertInstanceOf(PsrOnlyClock::class, $hostClock);
        self::assertSame($hostClock, $container->get(ClockInterface::class));

        $consumers = $container->getParameter('time_track_test.clock_consumers');
        self::assertIsArray($consumers);
        foreach ([CalendarHttpClient::class, CalendarTokenEndpoint::class] as $reported) {
            self::assertContains($reported, $consumers);
        }

        $withHostClock = 0;
        foreach ($consumers as $id) {
            self::assertIsString($id);
            $service = $container->get($id);
            self::assertIsObject($service, sprintf('%s is built', $id));
            $reflection = new ReflectionObject($service);
            if (!$reflection->hasProperty('clock')) {
                continue;
            }
            $clock = $reflection->getProperty('clock')->getValue($service);
            if ($clock !== null) {
                self::assertSame($hostClock, $clock, sprintf('%s uses the host clock', $id));
                ++$withHostClock;
            }
        }
        self::assertGreaterThanOrEqual(2, $withHostClock);
    }
}
