<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialSourceInterface;
use Nowo\TimeTrackBundle\Calendar\TaskKeyLookupInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsPolicyInterface;
use Nowo\TimeTrackBundle\DependencyInjection\Compiler\TimeTrackPortsPass;
use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface;
use Nowo\TimeTrackBundle\Port\Internal\CalendarCredentialProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserLocaleProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserPrivacyProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\EntryOwnersRepositoryInterface;
use Nowo\TimeTrackBundle\Port\Internal\IdleCheckSettingsProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\MeetingProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskKeyResolverInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\TeamContextProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\TimesheetApproverProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Port\Internal\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Port\Internal\WorkedTimeSourceInterface;
use Nowo\TimeTrackBundle\Port\Internal\WorkingCalendarProviderInterface;
use Nowo\TimeTrackBundle\Repository\EntryOwnerRefsRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeRecordInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarSourceInterface;

use function get_debug_type;
use function sprintf;
use function str_starts_with;

/**
 * Picks the object the bundle asks a host port through (REQ-ARCH-001 rule 3; since 2.0 the framework-free 1.23 twins
 * are the only host port interfaces):
 *
 * - the service implements the twin: it is wrapped in the twin's gate (stateless), which implements the bundle's
 *   internal shape of the port (namespace `Port\Internal`);
 * - it is one of the bundle's own defaults (a `Nowo\TimeTrackBundle\` class implementing the internal shape): it is
 *   returned as is;
 * - anything else: a `LogicException` for the configured ports (the build already failed for a class known before
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
     * The configured ports: name => [the bundle's internal shape, the host interface (1.23 twin), gate] (a batch task
     * directory gets its own gate).
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
     * The service the bundle asks for a configured port (the container factory of the port's slot).
     *
     * @param string $name a key of {@see self::PORTS}
     *
     * @throws LogicException when the port is unknown or the service implements neither the twin nor (a bundle default)
     *                        the internal shape
     */
    public static function port(string $name, mixed $service): object
    {
        if (!isset(self::PORTS[$name])) {
            throw new LogicException(sprintf('Unknown TimeTrack port "%s".', $name));
        }

        [$internal, $twin] = self::PORTS[$name];
        $gate              = self::gate($name, $service);
        if ($gate !== null) {
            return $gate;
        }
        if ($service instanceof $internal && self::isBundleClass($service::class)) {
            return $service;
        }

        throw new LogicException(sprintf('The TimeTrack %s service "%s" must implement "%s" (since 2.0 the only host interface of this port; see UPGRADING "To 2.0.0").', $name, get_debug_type($service), $twin));
    }

    /**
     * Whether the class is one of the bundle's own (its defaults, and its test doubles), which may implement the
     * internal shape of a port directly.
     */
    public static function isBundleClass(string $class): bool
    {
        return str_starts_with($class, 'Nowo\\TimeTrackBundle\\');
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
     * The locale extra of the profile provider (a bundle default's own internal shape, else the 1.23 twin of the host source), or null.
     */
    public static function locale(?object $profileProvider): ?ClientUserLocaleProviderInterface
    {
        $source = self::profileSource($profileProvider);

        return match (true) {
            $profileProvider instanceof ClientUserLocaleProviderInterface => $profileProvider,
            $source instanceof ClientUserLocaleSourceInterface            => new ClientUserLocaleSourceGate($source),
            default                                                       => null,
        };
    }

    /**
     * The days-off extra of the profile provider (a bundle default's own internal shape, else the 1.23 twin of the host source), or null.
     */
    public static function daysOff(?object $profileProvider): ?ClientUserDaysOffProviderInterface
    {
        $source = self::profileSource($profileProvider);

        return match (true) {
            $profileProvider instanceof ClientUserDaysOffProviderInterface => $profileProvider,
            $source instanceof ClientUserDaysOffSourceInterface            => new ClientUserDaysOffSourceGate($source),
            default                                                        => null,
        };
    }

    /**
     * The privacy extra of the profile provider (a bundle default's own internal shape, else the 1.23 twin of the host source), or null.
     */
    public static function privacy(?object $profileProvider): ?ClientUserPrivacyProviderInterface
    {
        $source = self::profileSource($profileProvider);

        return match (true) {
            $profileProvider instanceof ClientUserPrivacyProviderInterface => $profileProvider,
            $source instanceof ClientUserPrivacySourceInterface            => new ClientUserPrivacySourceGate($source),
            default                                                        => null,
        };
    }

    /**
     * The owner listing of the entry store (a bundle default's own internal shape, else the 1.23 twin of the host source), or null.
     */
    public static function entryOwners(?object $store): ?EntryOwnersRepositoryInterface
    {
        return match (true) {
            $store instanceof EntryOwnersRepositoryInterface && self::isBundleClass($store::class) => $store,
            $store instanceof EntryOwnerRefsRepositoryInterface                                    => new EntryOwnerRefsGate($store),
            default                                                                                => null,
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
