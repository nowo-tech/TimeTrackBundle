<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Controller\TimeTrackClientApiController;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetAccessGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Nowo\TimeTrackBundle\WorkingTime\WorkingTimeBalanceService;
use ReflectionMethod;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function in_array;
use function is_string;
use function method_exists;
use function preg_replace;
use function str_starts_with;
use function strtolower;

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
        private ?TimerService $timerService = null,
        private ?TranslatorInterface $translator = null,
        private ?ClientUserProfileProviderInterface $profileProvider = null,
        private ?UrlGeneratorInterface $urlGenerator = null,
        private ?string $workingTimeRoute = null,
    ) {
    }

    /**
     * Since 1.19 (spec 198): `GET me.links.workingTime`, the absolute https URL of the host's working-time page
     * (`client_api.links_working_time_route`; corrections and exports stay on the web), or null.
     */
    public function workingTimeLink(): ?string
    {
        if ($this->workingTimeRoute === null || $this->workingTimeRoute === '' || !$this->urlGenerator instanceof UrlGeneratorInterface) {
            return null;
        }
        try {
            $url = $this->urlGenerator->generate($this->workingTimeRoute, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return null;
        }

        return str_starts_with($url, 'https://') ? $url : null;
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
     * Since the GB fixes: ids of the imported entries whose task their owner may not see (title and description
     * masked, as `GET entries` / `GET export` since 1.11).
     *
     * @param list<TimeEntry> $entries
     *
     * @return array<string, true>
     */
    public function hiddenEntryIds(array $entries): array
    {
        return $this->timerService?->hiddenTaskEntryIds($entries) ?? [];
    }

    /**
     * The title of a masked entry (`reports.task_not_visible` in the user's language when the translator knows it).
     */
    public function hiddenTaskTitle(UserInterface $user): string
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return TimeEntry::HIDDEN_TASK_TITLE;
        }
        $title = $this->translator->trans('reports.task_not_visible', [], 'NowoTimeTrackBundle', $this->language($user));

        return $title === '' || $title === 'reports.task_not_visible' ? TimeEntry::HIDDEN_TASK_TITLE : $title;
    }

    /**
     * `GET me.timesheet`: the current period of the current scope, or null. Read only: a missing row is reported as
     * `open` and never created (audit GB-L1).
     *
     * @return array{scopeKey: string, periodKey: string, status: string, lockedThrough: string|null}|null
     */
    public function timesheetSummary(UserInterface $user): ?array
    {
        $scope = $this->scope($user, null);
        if (!$this->timesheets->isEnabled() || !$scope instanceof TimesheetScope) {
            return null;
        }
        // The user's own current period in one of their scopes: peek() cannot refuse it, and it never writes.
        $period    = $this->timesheets->period($user, $scope->key, 'current');
        $timesheet = $this->timesheets->peek($user, UserIdResolver::getId($user), $scope->key, $period);

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
     * Shipped language of the user's locale (profile provider, else an argumentless `getLocale()`), or null.
     */
    private function language(UserInterface $user): ?string
    {
        if ($this->profileProvider instanceof ClientUserLocaleProviderInterface) {
            $locale = $this->profileProvider->getLocale($user);
        } else {
            $locale = method_exists($user, 'getLocale') && (new ReflectionMethod($user, 'getLocale'))->getNumberOfRequiredParameters() === 0 ? $user->getLocale() : null;
        }
        if (!is_string($locale)) {
            return null;
        }
        $language = strtolower((string) preg_replace('/[_-].*$/s', '', $locale));

        return in_array($language, TimeTrackClientApiController::TRANSLATION_LOCALES, true) ? $language : null;
    }

    /**
     * Last locked date of a timesheet (null = not locked).
     */
    public static function lockedThrough(Timesheet $timesheet): ?string
    {
        return $timesheet->isLocked() ? $timesheet->getPeriodEnd() : null;
    }
}
