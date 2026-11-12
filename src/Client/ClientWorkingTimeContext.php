<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeImmutable;
use DateTimeZone;
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
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function date_default_timezone_get;
use function in_array;
use function is_string;
use function method_exists;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strtolower;

/**
 * Since 1.13: what the client API knows about a user's working time: the `GET me` feature flags (`workday`,
 * `timesheets`, `balance`) and `timesheet` summary, the user's tenant scope for a request (only one of their own
 * scopes; the first is the current one), and the entries no bundle path may change. Since 1.22 (Beacon spec 204
 * FR-029) also the user's current leave (`GET workday.leave`, `GET me` `features.leave` / `links.leave`) from the
 * optional {@see ClientUserLeaveProviderInterface}. Stateless between calls (worker mode, kernel reset false).
 */
final readonly class ClientWorkingTimeContext
{
    /** Since 1.22: the *Absent* leave type without a translator or a translation. */
    public const ABSENT = 'Absent';

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
        private ?ClientUserLeaveProviderInterface $leaveProvider = null,
        private ?string $leaveRoute = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Since 1.19 (spec 198): `GET me.links.workingTime`, the absolute https URL of the host's working-time page
     * (`client_api.links_working_time_route`; corrections and exports stay on the web), or null.
     */
    public function workingTimeLink(): ?string
    {
        return $this->httpsLink($this->workingTimeRoute);
    }

    /**
     * Since 1.22 (spec 204): true when the host reports leave (`client_api.leave_provider`). Without it no `leave` key
     * is sent anywhere, as in 1.21.
     */
    public function reportsLeave(): bool
    {
        return $this->leaveProvider instanceof ClientUserLeaveProviderInterface;
    }

    /**
     * Since 1.22 (spec 204): `GET me.links.leave`, the absolute https URL of the host's Leave page
     * (`client_api.links_leave_route`; requests, approvals and the team calendar stay on the web), or null.
     */
    public function leaveLink(): ?string
    {
        return $this->httpsLink($this->leaveRoute);
    }

    /**
     * Since 1.22 (spec 204 FR-029): `GET workday.leave` — `{type, until}` when the host says the user is on leave on the
     * workday's date, else null. `type` is the cleaned public label of {@see ClientUserLeave::typed()}, or the bundle's
     * *Absent* label in the user's language for {@see ClientUserLeave::absent()} (medical leave) and for a label that
     * is empty or not valid UTF-8. A leave that ended before that date, a provider that throws and a workday date that
     * cannot be read all give null, with a `warning` log without user data.
     *
     * @return array{type: string, until: string}|null
     */
    public function leave(UserInterface $user, WorkdayState $state): ?array
    {
        if (!$this->leaveProvider instanceof ClientUserLeaveProviderInterface) {
            return null;
        }
        $day = $this->day($state);
        if (!$day instanceof DateTimeImmutable) {
            $this->warnLeave('invalid workday date');

            return null;
        }
        try {
            $leave = $this->leaveProvider->getCurrentLeave(UserIdResolver::getId($user), $day);
        } catch (Throwable $e) {
            // The workday API keeps working; the exception message may hold host data, so only its class is logged.
            $this->warnLeave('provider failed', $e::class);

            return null;
        }
        if (!$leave instanceof ClientUserLeave) {
            return null;
        }
        if ($leave->until < $state->date) {
            $this->warnLeave('leave ended before the workday date');

            return null;
        }
        $type = $leave->type !== null && preg_match('//u', $leave->type) === 1 ? ClientDaysOffNormalizer::label($leave->type) : null;

        return ['type' => $type ?? $this->absentLabel($user), 'until' => $leave->until];
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
     * The bundle's *Absent* label (`client_api.leave_absent`) in the user's language when the bundle ships it, else the
     * translator's default locale; English without a translator.
     */
    private function absentLabel(UserInterface $user): string
    {
        if (!$this->translator instanceof TranslatorInterface) {
            return self::ABSENT;
        }
        $label = $this->translator->trans('client_api.leave_absent', [], 'NowoTimeTrackBundle', $this->language($user));

        return $label === '' || $label === 'client_api.leave_absent' ? self::ABSENT : $label;
    }

    /**
     * Start of the workday's date in its time zone (the PHP default one when it is not valid), or null.
     */
    private function day(WorkdayState $state): ?DateTimeImmutable
    {
        try {
            $zone = new DateTimeZone($state->timezone);
        } catch (Throwable) {
            $zone = new DateTimeZone(date_default_timezone_get());
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $state->date, $zone);

        return $day instanceof DateTimeImmutable && $day->format('Y-m-d') === $state->date ? $day : null;
    }

    /**
     * One warning about the leave of a request: the reason only, no user data, no dates, no labels.
     */
    private function warnLeave(string $reason, ?string $exception = null): void
    {
        $this->logger?->warning('TimeTrack client leave from the provider was ignored.', ['bundle' => 'time_track', 'action' => 'client_workday', 'reason' => $reason] + ($exception === null ? [] : ['exception' => $exception]));
    }

    /**
     * Absolute https URL of a host route, or null (no route, no router, an unknown route or a non-https URL).
     */
    private function httpsLink(?string $route): ?string
    {
        if ($route === null || $route === '' || !$this->urlGenerator instanceof UrlGeneratorInterface) {
            return null;
        }
        try {
            $url = $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return null;
        }

        return str_starts_with($url, 'https://') ? $url : null;
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
