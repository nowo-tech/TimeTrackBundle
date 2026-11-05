<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\WorkingTime;

use DateTimeZone;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Enum\TimesheetPeriodKind;
use Nowo\TimeTrackBundle\Repository\BalanceLedgerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimesheetRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_merge;
use function is_int;
use function max;
use function min;
use function sprintf;
use function substr;

/**
 * Since 1.13: hours balance of a user in a scope (contract §4.4): reads the working calendar, the worked time and the
 * ledger, runs the overtime policy and the pure {@see WorkingTimeBalanceCalculator}. Nothing is cached across
 * requests. The running balance starts after the last **locked** timesheet ending before the range (its approved
 * snapshot's `balanceSeconds` is reused: locked periods do not change), else at `working_time.balance.start_date`, else
 * 366 days before the end of the range. Weekly overtime is evaluated on whole ISO weeks.
 */
final readonly class WorkingTimeBalanceService
{
    /** Longest range of one report, in days. */
    public const MAX_RANGE_DAYS = 366;

    private ClockInterface $clock;

    /**
     * @param string|null $balanceStartDate `working_time.balance.start_date` (Y-m-d)
     * @param bool $enabled `working_time.enabled`
     */
    public function __construct(
        private WorkingCalendarProviderInterface $calendar,
        private WorkedTimeSourceInterface $workedSource,
        private OvertimePolicyInterface $policy,
        private BalanceLedgerRepositoryInterface $ledger,
        private TimesheetRepositoryInterface $timesheets,
        private TimesheetTimezoneResolver $timezoneResolver,
        ?ClockInterface $clock = null,
        private ?string $balanceStartDate = null,
        private bool $enabled = true,
        private ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Whether the working-time feature is on.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether a balance exists for the user in the scope today (`features.balance`).
     */
    public function isAvailable(UserInterface $user, string $scopeKey): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $today = Period::date($this->today($user, $scopeKey));

        return $this->calendar->days($user, $scopeKey, $today, $today) !== null;
    }

    /**
     * Local date of today in the user's zone for the scope.
     */
    public function today(UserInterface $user, string $scopeKey): string
    {
        // @igor-ignore - reads the clock (a new immutable value); no worker state.
        return $this->clock->now()->setTimezone(new DateTimeZone($this->timezoneResolver->resolve($user, $scopeKey)))->format('Y-m-d');
    }

    /**
     * The worked-time source (whether it is the legal register).
     */
    public function isLegalRecord(): bool
    {
        return $this->workedSource->isLegalRecord();
    }

    /**
     * The report of [$fromDate, $toDate] (≤ 366 days), or null when the feature is off or the host has no calendar
     * for the user and scope.
     *
     * @throws InvalidArgumentException When a date is invalid, the range is reversed or longer than 366 days
     */
    public function report(UserInterface $user, string $scopeKey, string $fromDate, string $toDate): ?BalanceReport
    {
        $from = Period::date($fromDate);
        $to   = Period::date($toDate);
        if ($to < $from || $from->diff($to)->days >= self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException(sprintf('The balance range must be 1 to %d days.', self::MAX_RANGE_DAYS));
        }
        if (!$this->enabled) {
            return null;
        }

        $ownerId  = UserIdResolver::getId($user);
        $timezone = $this->timezoneResolver->resolve($user, $scopeKey);
        // @igor-ignore - reads the clock (a new immutable value); no worker state.
        $today = $this->clock->now()->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');

        [$balanceFrom, $opening] = $this->balanceStart($ownerId, $scopeKey, $fromDate, $toDate);
        $queryFrom               = min($balanceFrom, Period::containing(TimesheetPeriodKind::Week, $fromDate)->start);
        $queryTo                 = Period::containing(TimesheetPeriodKind::Week, $toDate)->end;

        $calendar = [];
        $worked   = [];
        foreach ($this->chunks($queryFrom, $queryTo) as [$chunkFrom, $chunkTo]) {
            $days = CalendarDayNormalizer::normalize($this->calendar->days($user, $scopeKey, Period::date($chunkFrom), Period::date($chunkTo)), $chunkFrom, $chunkTo, $timezone, $this->logger);
            if ($days === null) {
                return null;
            }
            $calendar = array_merge($calendar, $days);
            $worked   = array_merge($worked, $this->workedSource->workedDays($user, $scopeKey, Period::date($chunkFrom), Period::date($chunkTo)));
        }

        $ledgerFrom = min($queryFrom, sprintf('%04d-01-01', (int) substr($today, 0, 4) - 1));
        $lines      = $this->withReversedLines($this->ledger->findByOwner($ownerId, $scopeKey, $ledgerFrom, max($toDate, $today)));
        $legal      = $this->workedSource->isLegalRecord();
        $overtime   = $this->policy->evaluate(new OvertimeInput($calendar, $worked, $today, $legal, $lines));

        return (new WorkingTimeBalanceCalculator())->compute(new BalanceQuery(
            $fromDate,
            $toDate,
            $today,
            $calendar,
            $worked,
            $lines,
            $balanceFrom,
            $opening,
            $overtime,
            $legal ? 'register' : 'task_time',
            $legal,
            $timezone,
        ));
    }

    /**
     * @return array{string, int} first date of the running balance and the balance before it
     */
    private function balanceStart(string $ownerId, string $scopeKey, string $fromDate, string $toDate): array
    {
        $locked  = $this->timesheets->findLatestLockedBefore($ownerId, $scopeKey, $fromDate);
        $cached  = $locked instanceof Timesheet ? ($locked->getTotals()['balanceSeconds'] ?? null) : null;
        $default = $this->balanceStartDate ?? Period::date($toDate)->modify('-365 days')->format('Y-m-d');
        if ($locked instanceof Timesheet && is_int($cached) && $locked->getPeriodEnd() >= $default) {
            return [Period::date($locked->getPeriodEnd())->modify('+1 day')->format('Y-m-d'), $cached];
        }

        return [$default, 0];
    }

    /**
     * Adds the lines that reversals of the list point to (so the calculator knows what each reversal cancels).
     *
     * @param list<BalanceLedgerLine> $lines
     *
     * @return list<BalanceLedgerLine>
     */
    private function withReversedLines(array $lines): array
    {
        $known = [];
        foreach ($lines as $line) {
            $known[$line->getId()] = true;
        }
        foreach ($lines as $line) {
            $target = $line->getReversesId();
            if ($target !== null && !isset($known[$target])) {
                $reversed = $this->ledger->find($target);
                if ($reversed instanceof BalanceLedgerLine) {
                    $lines[]        = $reversed;
                    $known[$target] = true;
                }
            }
        }

        return $lines;
    }

    /**
     * Calls of at most 366 days covering [$from, $to].
     *
     * @return list<array{string, string}>
     */
    private function chunks(string $from, string $to): array
    {
        $chunks = [];
        $start  = Period::date($from);
        $end    = Period::date($to);
        while ($start <= $end) {
            $chunkEnd = min($end, $start->modify('+' . (self::MAX_RANGE_DAYS - 1) . ' days'));
            $chunks[] = [$start->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
            $start    = $chunkEnd->modify('+1 day');
        }

        return $chunks;
    }
}
