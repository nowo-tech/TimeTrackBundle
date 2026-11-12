<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialProviderInterface;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialSourceInterface;
use Nowo\TimeTrackBundle\Calendar\TaskKeyLookupInterface;
use Nowo\TimeTrackBundle\Calendar\TaskKeyResolverInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsPolicyInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsProviderInterface;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeTrackPortsPass;
use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface;
use Nowo\TimeTrackBundle\Repository\EntryOwnerRefsRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\EntryOwnersRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverProviderInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeRecordInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeSourceInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarSourceInterface;

use function get_debug_type;
use function sprintf;

/**
 * Picks the interface the bundle asks a host service through (1.23.0, REQ-ARCH-001 rule 3):
 *
 * - the service implements the deprecated interface: it is returned as is and asked exactly as in 1.22 (also when it
 *   implements the 1.23 twin too, until 2.0);
 * - it implements only the 1.23 twin: it is wrapped in the twin's gate (stateless);
 * - neither: a `LogicException` for the configured ports (the build already failed for a class known before
 *   compilation, see {@see TimeTrackPortsPass}), null for the optional extras.
 *
 * The configured ports are container services built by {@see self::port()} (one decision per container); the optional
 * extras of a service are resolved per call. Stateless.
 *
 * @internal
 */
final class Ports
{
    /**
     * The configured ports: name => [deprecated interface, 1.23 twin, gate] (a batch task directory gets its own gate).
     *
     * @var array<string, array{0: class-string, 1: class-string, 2: class-string}>
     */
    public const PORTS = [
        'task_provider'         => [TaskProviderInterface::class, TaskDirectoryInterface::class, TaskDirectoryGate::class],
        'team_context_provider' => [TeamContextProviderInterface::class, TeamDirectoryInterface::class, TeamDirectoryGate::class],
        'user_reference'        => [UserReferenceResolverInterface::class, UserReferenceProviderInterface::class, UserReferenceProviderGate::class],
        'scope_provider'        => [TimesheetScopeProviderInterface::class, TimesheetScopePolicyInterface::class, TimesheetScopePolicyGate::class],
        'approver_provider'     => [TimesheetApproverProviderInterface::class, TimesheetApproverPolicyInterface::class, TimesheetApproverPolicyGate::class],
        'task_key_resolver'     => [TaskKeyResolverInterface::class, TaskKeyLookupInterface::class, TaskKeyLookupGate::class],
        'credential_provider'   => [CalendarCredentialProviderInterface::class, CalendarCredentialSourceInterface::class, CalendarCredentialSourceGate::class],
        'meeting_provider'      => [MeetingProviderInterface::class, MeetingSourceInterface::class, MeetingSourceGate::class],
        'workday_provider'      => [WorkdayProviderInterface::class, WorkdaySourceInterface::class, WorkdaySourceGate::class],
        'idle_check_provider'   => [IdleCheckSettingsProviderInterface::class, IdleCheckSettingsPolicyInterface::class, IdleCheckSettingsPolicyGate::class],
        'credential_issuer'     => [ClientCredentialIssuerInterface::class, ClientCredentialMinterInterface::class, ClientCredentialMinterGate::class],
        'task_access_checker'   => [TaskAccessCheckerInterface::class, TaskAccessPolicyInterface::class, TaskAccessPolicyGate::class],
        'profile_provider'      => [ClientUserProfileProviderInterface::class, ClientUserProfileSourceInterface::class, ClientUserProfileSourceGate::class],
        'working_calendar'      => [WorkingCalendarProviderInterface::class, WorkingCalendarSourceInterface::class, WorkingCalendarSourceGate::class],
        'worked_time_source'    => [WorkedTimeSourceInterface::class, WorkedTimeRecordInterface::class, WorkedTimeRecordGate::class],
    ];

    /**
     * The service the bundle asks for a configured port (the container factory of `nowo_time_track.port.<name>`).
     *
     * @param string $name a key of {@see self::PORTS}
     *
     * @throws LogicException when the port is unknown or the service implements neither interface
     */
    public static function port(string $name, mixed $service): object
    {
        if (!isset(self::PORTS[$name])) {
            throw new LogicException(sprintf('Unknown TimeTrack port "%s".', $name));
        }

        [$legacy, $twin] = self::PORTS[$name];
        if ($service instanceof $legacy) {
            return $service;
        }

        return self::gate($name, $service) ?? throw new LogicException(sprintf('The TimeTrack %s service "%s" must implement "%s" (or the deprecated "%s").', $name, get_debug_type($service), $twin, $legacy));
    }

    /**
     * The twin's gate over a service that implements the 1.23 twin of the port, or null.
     */
    private static function gate(string $name, mixed $service): ?object
    {
        return match (true) {
            $name === 'task_provider' && $service instanceof BatchTaskDirectoryInterface             => new BatchTaskDirectoryGate($service),
            $name === 'task_provider' && $service instanceof TaskDirectoryInterface                  => new TaskDirectoryGate($service),
            $name === 'team_context_provider' && $service instanceof TeamDirectoryInterface          => new TeamDirectoryGate($service),
            $name === 'user_reference' && $service instanceof UserReferenceProviderInterface         => new UserReferenceProviderGate($service),
            $name === 'scope_provider' && $service instanceof TimesheetScopePolicyInterface          => new TimesheetScopePolicyGate($service),
            $name === 'approver_provider' && $service instanceof TimesheetApproverPolicyInterface    => new TimesheetApproverPolicyGate($service),
            $name === 'task_key_resolver' && $service instanceof TaskKeyLookupInterface              => new TaskKeyLookupGate($service),
            $name === 'credential_provider' && $service instanceof CalendarCredentialSourceInterface => new CalendarCredentialSourceGate($service),
            $name === 'meeting_provider' && $service instanceof MeetingSourceInterface               => new MeetingSourceGate($service),
            $name === 'workday_provider' && $service instanceof WorkdaySourceInterface               => new WorkdaySourceGate($service),
            $name === 'idle_check_provider' && $service instanceof IdleCheckSettingsPolicyInterface  => new IdleCheckSettingsPolicyGate($service),
            $name === 'credential_issuer' && $service instanceof ClientCredentialMinterInterface     => new ClientCredentialMinterGate($service),
            $name === 'task_access_checker' && $service instanceof TaskAccessPolicyInterface         => new TaskAccessPolicyGate($service),
            $name === 'profile_provider' && $service instanceof ClientUserProfileSourceInterface     => new ClientUserProfileSourceGate($service),
            $name === 'working_calendar' && $service instanceof WorkingCalendarSourceInterface       => new WorkingCalendarSourceGate($service),
            $name === 'worked_time_source' && $service instanceof WorkedTimeRecordInterface          => new WorkedTimeRecordGate($service),
            default                                                                                  => null,
        };
    }

    /**
     * The locale extra of the profile provider (deprecated interface first, then the 1.23 twin), or null.
     */
    public static function locale(?object $profileProvider): ?ClientUserLocaleProviderInterface
    {
        $source = self::profileSource($profileProvider);

        return match (true) {
            $profileProvider instanceof ClientUserLocaleProviderInterface => $profileProvider,
            $source instanceof ClientUserLocaleProviderInterface          => $source,
            $source instanceof ClientUserLocaleSourceInterface            => new ClientUserLocaleSourceGate($source),
            default                                                       => null,
        };
    }

    /**
     * The days-off extra of the profile provider (deprecated interface first, then the 1.23 twin), or null.
     */
    public static function daysOff(?object $profileProvider): ?ClientUserDaysOffProviderInterface
    {
        $source = self::profileSource($profileProvider);

        return match (true) {
            $profileProvider instanceof ClientUserDaysOffProviderInterface => $profileProvider,
            $source instanceof ClientUserDaysOffProviderInterface          => $source,
            $source instanceof ClientUserDaysOffSourceInterface            => new ClientUserDaysOffSourceGate($source),
            default                                                        => null,
        };
    }

    /**
     * The privacy extra of the profile provider (deprecated interface first, then the 1.23 twin), or null.
     */
    public static function privacy(?object $profileProvider): ?ClientUserPrivacyProviderInterface
    {
        $source = self::profileSource($profileProvider);

        return match (true) {
            $profileProvider instanceof ClientUserPrivacyProviderInterface => $profileProvider,
            $source instanceof ClientUserPrivacyProviderInterface          => $source,
            $source instanceof ClientUserPrivacySourceInterface            => new ClientUserPrivacySourceGate($source),
            default                                                        => null,
        };
    }

    /**
     * The owner listing of the entry store (deprecated interface first, then the 1.23 twin), or null.
     */
    public static function entryOwners(?object $store): ?EntryOwnersRepositoryInterface
    {
        return match (true) {
            $store instanceof EntryOwnersRepositoryInterface    => $store,
            $store instanceof EntryOwnerRefsRepositoryInterface => new EntryOwnerRefsGate($store),
            default                                             => null,
        };
    }

    /**
     * The object behind a profile provider whose extras are asked: the host source behind the gate, else itself.
     */
    private static function profileSource(?object $profileProvider): ?object
    {
        return $profileProvider instanceof ClientUserProfileSourceGate ? $profileProvider->source() : $profileProvider;
    }
}
