<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support\Ports;

use Nowo\TimeTrackBundle\Tests\Stub\LeaveDaysOffProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\MutableProfileProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubCalendarCredentialProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubMeetingProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubTaskKeyResolver;
use Nowo\TimeTrackBundle\Tests\Stub\StubTimesheetApproverProvider;
use Nowo\TimeTrackBundle\Tests\Stub\StubWorkdayProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedScopeProvider;
use Nowo\TimeTrackBundle\Tests\Support\FixedWorkingCalendar;

use function is_array;
use function is_string;

/**
 * 1.23.0 (REQ-ARCH-001 rule 3): the twin variants of the functional suites. Replaces every stub host port configured in
 * a `nowo_time_track` configuration by its framework-free twin adapter ({@see TwinPorts}), which shares the stub's
 * state: the suite then runs the same scenarios through the 1.23 twins and the bundle's gates.
 */
final class TwinPortConfig
{
    /** Stub service => twin service. */
    public const TWINS = [
        FixedScopeProvider::class             => TwinScopePolicy::class,
        StubTimesheetApproverProvider::class  => TwinApproverPolicy::class,
        FixedWorkingCalendar::class           => TwinWorkingCalendar::class,
        StubWorkdayProvider::class            => TwinWorkdaySource::class,
        StubMeetingProvider::class            => TwinMeetingSource::class,
        MutableProfileProvider::class         => TwinProfileSource::class,
        LeaveDaysOffProfileProvider::class    => TwinDaysOffProfileSource::class,
        StubTaskKeyResolver::class            => TwinTaskKeyLookup::class,
        StubCalendarCredentialProvider::class => TwinCalendarCredentialSource::class,
    ];

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    public static function twin(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = self::twin($value);
            } elseif (is_string($value) && isset(self::TWINS[$value])) {
                $config[$key] = self::TWINS[$value];
            }
        }

        return $config;
    }
}
