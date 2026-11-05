<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13: what the client API knows about a user's working time: the `GET me` feature flags (`workday`,
 * `timesheets`, `balance`) and `timesheet` summary, the user's tenant scope for a request (only one of their own
 * scopes; the first is the current one), and the entries no bundle path may change.
 */
final readonly class ClientWorkingTimeContext
{
    /**
     * Receives the working-time services.
     */
    public function __construct(
        private TimesheetService $timesheets,
        private WorkingTimeBalanceService $balance,
        private TimesheetAccessGuard $accessGuard,
        private PeriodLockGuard $lockGuard,
        private ?WorkdayProviderInterface $workdayProvider = null,
    ) {
    }

    /**
     * The workday provider (null = off).
     */
    public function workdayProvider(): ?WorkdayProviderInterface
    {
        return $this->workdayProvider;
    }

    /**
     * The timesheet service.
     */
    public function timesheets(): TimesheetService
    {
        return $this->timesheets;
    }

    /**
     * The balance service.
     */
    public function balance(): WorkingTimeBalanceService
    {
        return $this->balance;
    }

    /**
     * The user's scope for a request: the requested key when it is one of the user's scopes, else (no request) the
     * current scope; null when there is none.
     */
    public function scope(UserInterface $user, ?string $requested): ?TimesheetScope
    {
        $scopes = $this->accessGuard->scopesOf($user);
        if ($requested === null || $requested === '') {
            return $scopes[0] ?? null;
        }
        foreach ($scopes as $scope) {
            if ($scope->key === $requested) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * `GET me.features` additions.
     *
     * @return array{workday: bool, timesheets: bool, balance: bool}
     */
    public function features(UserInterface $user): array
    {
        $scope = $this->scope($user, null);

        return [
            'workday'    => $this->workdayProvider?->today($user) instanceof WorkdayState,
            'timesheets' => $this->timesheets->isEnabled() && $scope instanceof TimesheetScope,
            'balance'    => $scope instanceof TimesheetScope && $this->balance->isAvailable($user, $scope->key),
        ];
    }

    /**
     * `GET me.timesheet`: the current period of the current scope, or null.
     *
     * @return array{scopeKey: string, periodKey: string, status: string, lockedThrough: string|null}|null
     */
    public function timesheetSummary(UserInterface $user): ?array
    {
        $scope = $this->scope($user, null);
        if (!$this->timesheets->isEnabled() || !$scope instanceof TimesheetScope) {
            return null;
        }
        try {
            $period    = $this->timesheets->period($user, $scope->key, 'current');
            $timesheet = $this->timesheets->view($user, UserIdResolver::getId($user), $scope->key, $period);
        } catch (TimesheetException) {
            return null;
        }

        return ['scopeKey' => $scope->key, 'periodKey' => $period->key, 'status' => $timesheet->getStatus()->value, 'lockedThrough' => self::lockedThrough($timesheet)];
    }

    /**
     * Ids of entries no bundle path may change (`locked` on entries and the CSV).
     *
     * @param list<TimeEntry> $entries
     *
     * @return array<string, true>
     */
    public function blockedEntryIds(array $entries): array
    {
        return $this->lockGuard->blockedEntryIds($entries);
    }

    /**
     * Last locked date of a timesheet (null = not locked).
     */
    public static function lockedThrough(Timesheet $timesheet): ?string
    {
        return $timesheet->isLocked() ? $timesheet->getPeriodEnd() : null;
    }
}
