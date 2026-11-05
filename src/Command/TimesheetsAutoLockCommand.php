<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use DateInterval;
use DateTimeZone;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Repository\EntryOwnersRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetTimezoneResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function max;
use function min;
use function sprintf;

/**
 * Since 1.13: locks every period that ended more than `timesheets.auto_lock_after_days` days ago (contract §5.7;
 * hosts schedule it daily). Rows are created only for users with at least one scoped entry in the period (never for
 * every user); a locked period is skipped. Each lock is audited (`auto_locked`, actor `system`).
 */
#[AsCommand(
    name: 'nowo:time-track:timesheets:auto-lock',
    description: 'Lock timesheet periods that ended more than timesheets.auto_lock_after_days days ago.',
)]
final class TimesheetsAutoLockCommand extends Command
{
    private readonly ClockInterface $clock;

    /**
     * @param int|null $afterDays `timesheets.auto_lock_after_days` (null = off)
     */
    public function __construct(
        private readonly TimesheetService $timesheets,
        private readonly TimeEntryRepositoryInterface $entries,
        private readonly TimesheetTimezoneResolver $timezoneResolver,
        private readonly ?int $afterDays = null,
        ?ClockInterface $clock = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
        $this->clock = $clock ?? new NativeClock();
    }

    protected function configure(): void
    {
        $this->addOption('lookback-days', null, InputOption::VALUE_REQUIRED, 'How many days before the lock cut-off to scan (1-366).', '62');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->timesheets->isEnabled() || $this->afterDays === null || !$this->entries instanceof EntryOwnersRepositoryInterface) {
            $io->note('Auto-lock is off (timesheets.enabled / timesheets.auto_lock_after_days).');

            return Command::SUCCESS;
        }

        $lookback = max(1, min(366, (int) $input->getOption('lookback-days')));
        $now      = $this->clock->now();
        $to       = $now->sub(new DateInterval(sprintf('P%dD', $this->afterDays)));
        $from     = $to->sub(new DateInterval(sprintf('P%dD', $lookback)));
        $locked   = 0;
        foreach ($this->entries->ownersWithEntries($from, $to) as ['user' => $owner, 'scopeKey' => $scopeKey]) {
            $timezone = $this->timezoneResolver->resolve($owner, $scopeKey);
            // @igor-ignore - immutable local value of this run, not a shared service.
            $cutoff  = $to->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
            $periods = [];
            foreach ($this->entries->findByUserAndPeriod(UserIdResolver::getId($owner), $from, $to) as $entry) {
                if ($entry->getScopeKey() !== $scopeKey) {
                    continue;
                }
                $period = Period::containing($this->timesheets->periodKind(), StorageTime::localDate($entry->getStartedAt(), $timezone));
                if ($period->end < $cutoff) {
                    $periods[$period->key] = $period;
                }
            }
            foreach ($periods as $period) {
                if ($this->timesheets->autoLock($owner, $scopeKey, $period) instanceof Timesheet) {
                    ++$locked;
                }
            }
        }

        $this->logger->info('TimeTrack timesheet auto-lock finished.', ['bundle' => 'time_track', 'action' => 'timesheets_auto_lock', 'locked' => $locked]);
        $io->success(sprintf('Locked %d period(s).', $locked));

        return Command::SUCCESS;
    }
}
