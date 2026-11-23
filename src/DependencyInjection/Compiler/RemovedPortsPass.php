<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Calendar\CalendarCredentialSourceInterface;
use Nowo\TimeTrackBundle\Calendar\TaskKeyLookupInterface;
use Nowo\TimeTrackBundle\Client\ClientCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserLocaleSourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserPrivacySourceInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileSourceInterface;
use Nowo\TimeTrackBundle\Client\IdleCheckSettingsPolicyInterface;
use Nowo\TimeTrackBundle\Integration\BatchTaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\TaskDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\TeamDirectoryInterface;
use Nowo\TimeTrackBundle\Integration\UserReferenceProviderInterface;
use Nowo\TimeTrackBundle\Meeting\MeetingSourceInterface;
use Nowo\TimeTrackBundle\Repository\EntryOwnerRefsRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\FilteredImportedTimeEntryStoreInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryStoreInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetApproverPolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\Workday\WorkdaySourceInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeRecordInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarSourceInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

use function array_column;
use function class_exists;
use function interface_exists;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * 2.0 (REQ-ARCH-001 rule 3, audit lesson W1-1 / XR-WF-M1): fails the container build on every service that still uses
 * one of the host port interfaces deprecated in 1.23.0 (runtime notices since 1.33.0) and removed in 2.0, instead of
 * letting Symfony drop it silently. A host task access checker, task directory or scope policy that disappeared would
 * leave TimeTrack on its default (the stub task provider, the single scope, the task provider as access check: wider
 * access) while the build succeeds.
 *
 * It refuses:
 * - a service or alias under a removed id (`TaskProviderInterface: '@App\Tasks'`, `#[AsAlias]` of it);
 * - a decorator of a removed id (`#[AsDecorator]`, `decorates:`), `on_invalid: ignore` included;
 * - a definition whose class cannot be loaded because it implements a removed interface: Symfony records
 *   `Class "<removed interface>" not found while loading "<class>"` (or the PHP error naming it) on an autodiscovered
 *   class and would otherwise remove it unused. Only the exact name of a removed interface matches; an unrelated missing
 *   class keeps Symfony's own lazy error;
 * - the service behind a port's slot (the configured `task_provider`, `client_api.profile_provider`, ... and the time
 *   entry store) whose class cannot be loaded for the same reason.
 *
 * Runs before optimization at a high priority (1000), while the loader errors are still on the definitions and before
 * any pass removes a definition, and again at priority 99, after the passes of other bundles that may add decorators.
 *
 * @internal
 */
final class RemovedPortsPass implements CompilerPassInterface
{
    /**
     * Removed interface => [the interface to implement instead (its 1.23 twin), where it is configured].
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    public const REMOVED = [
        'Nowo\TimeTrackBundle\Integration\TaskProviderInterface'                       => [TaskDirectoryInterface::class, 'nowo_time_track.task_provider'],
        'Nowo\TimeTrackBundle\Integration\BatchTaskProviderInterface'                  => [BatchTaskDirectoryInterface::class, 'nowo_time_track.task_provider'],
        'Nowo\TimeTrackBundle\Integration\TeamContextProviderInterface'                => [TeamDirectoryInterface::class, 'nowo_time_track.team_context_provider'],
        'Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface'              => [UserReferenceProviderInterface::class, 'an alias "' . UserReferenceProviderInterface::class . '"'],
        'Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface'               => [TimesheetScopePolicyInterface::class, 'nowo_time_track.timesheets.scope_provider'],
        'Nowo\TimeTrackBundle\Timesheet\TimesheetApproverProviderInterface'            => [TimesheetApproverPolicyInterface::class, 'nowo_time_track.timesheets.approver_provider'],
        'Nowo\TimeTrackBundle\Calendar\TaskKeyResolverInterface'                       => [TaskKeyLookupInterface::class, 'nowo_time_track.calendar.task_key_resolver'],
        'Nowo\TimeTrackBundle\Calendar\CalendarCredentialProviderInterface'            => [CalendarCredentialSourceInterface::class, 'nowo_time_track.calendar.credential_provider'],
        'Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface'                        => [MeetingSourceInterface::class, 'nowo_time_track.meetings.provider'],
        'Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface'                        => [WorkdaySourceInterface::class, 'nowo_time_track.client_api.workday_provider'],
        'Nowo\TimeTrackBundle\Client\IdleCheckSettingsProviderInterface'               => [IdleCheckSettingsPolicyInterface::class, 'nowo_time_track.client_api.idle_check.provider'],
        'Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface'                  => [ClientCredentialMinterInterface::class, 'nowo_time_track.client_api.credential_issuer'],
        'Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface'                     => [TaskAccessPolicyInterface::class, 'nowo_time_track.client_api.task_access_checker'],
        'Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface'               => [ClientUserProfileSourceInterface::class, 'nowo_time_track.client_api.profile_provider'],
        'Nowo\TimeTrackBundle\Client\ClientUserLocaleProviderInterface'                => [ClientUserLocaleSourceInterface::class, 'nowo_time_track.client_api.profile_provider'],
        'Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface'               => [ClientUserDaysOffSourceInterface::class, 'nowo_time_track.client_api.profile_provider'],
        'Nowo\TimeTrackBundle\Client\ClientUserPrivacyProviderInterface'               => [ClientUserPrivacySourceInterface::class, 'nowo_time_track.client_api.profile_provider'],
        'Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface'            => [WorkingCalendarSourceInterface::class, 'nowo_time_track.working_time.calendar_provider'],
        'Nowo\TimeTrackBundle\WorkingTime\WorkedTimeSourceInterface'                   => [WorkedTimeRecordInterface::class, 'nowo_time_track.working_time.worked_time_source'],
        'Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface'         => [ImportedTimeEntryStoreInterface::class, 'the "' . TimeEntryRepositoryInterface::class . '" alias'],
        'Nowo\TimeTrackBundle\Repository\FilteredImportedTimeEntryRepositoryInterface' => [FilteredImportedTimeEntryStoreInterface::class, 'the "' . TimeEntryRepositoryInterface::class . '" alias'],
        'Nowo\TimeTrackBundle\Repository\EntryOwnersRepositoryInterface'               => [EntryOwnerRefsRepositoryInterface::class, 'the "' . TimeEntryRepositoryInterface::class . '" alias'],
    ];

    /**
     * The build error for a service on a removed port interface.
     *
     * @param string $service The service id (or class)
     * @param string $removed The removed interface
     */
    public static function message(string $service, string $removed): string
    {
        [$twin, $option] = self::REMOVED[$removed];

        return sprintf('nowo_time_track: the service "%s" uses %s, which was deprecated in 1.23.0 and removed in 2.0. Implement %s instead and keep it configured as %s (a decorator decorates that service); see UPGRADING.md, "To 2.0.0", "Host port interfaces".', $service, $removed, $twin, $option);
    }

    /**
     * The removed interface an error message names exactly (quoted, as Symfony and PHP quote class names), or null.
     */
    public static function named(string $error): ?string
    {
        foreach (self::REMOVED as $removed => $twin) {
            if (str_contains($error, '"' . $removed . '"')) {
                return $removed;
            }
        }

        return null;
    }

    /**
     * @throws LogicException When a service still uses a removed port interface
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::REMOVED as $removed => $twin) {
            if ($container->hasAlias($removed) || $container->hasDefinition($removed)) {
                throw new LogicException(self::message($container->hasAlias($removed) ? (string) $container->getAlias($removed) : $removed, $removed));
            }
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            $decorated = $definition->getDecoratedService();
            if ($decorated !== null && isset(self::REMOVED[$decorated[0]])) {
                throw new LogicException(self::message($id, $decorated[0]));
            }
            foreach ($definition->getErrors() as $error) {
                if (($removed = self::named($error)) !== null) {
                    throw new LogicException(self::message($id, $removed));
                }
            }
        }
        $slots   = array_column(TimeTrackPortsPass::SLOTS, 0);
        $slots[] = TimeEntryRepositoryInterface::class;
        foreach ($slots as $slot) {
            $class = $this->classBehind($container, $slot);
            if ($class !== null && ($error = $this->loadError($class)) !== null && ($named = self::named($error)) !== null) {
                throw new LogicException(self::message($class, $named));
            }
        }
    }

    /**
     * Why the class cannot be loaded, or null when it can (or is unknown to the autoloader).
     */
    private function loadError(string $class): ?string
    {
        try {
            if (!class_exists($class)) {
                interface_exists($class);
            }
        } catch (Throwable $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    /**
     * The class of the service a slot points at, when the definition names one.
     */
    private function classBehind(ContainerBuilder $container, string $id): ?string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);

        return is_string($class) ? $class : null;
    }
}
