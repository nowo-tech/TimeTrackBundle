<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use Nowo\TimeTrackBundle\Repository\TimesheetRetentionRepositoryInterface;
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

use function sprintf;

/**
 * Since 1.13: the retention purge of timesheets, their audit rows and the ledger (contract §12.4): everything whose
 * period ended before the first day of the current month minus `timesheets.retention_years` (4–10) years, i.e. kept
 * at least that many years after the end of the period's month. The only delete path of those rows.
 */
#[AsCommand(
    name: 'nowo:time-track:timesheets:purge',
    description: 'Delete timesheets, audit rows and ledger lines past timesheets.retention_years.',
)]
final class TimesheetsPurgeCommand extends Command
{
    private readonly ClockInterface $clock;

    /**
     * @param int $retentionYears `timesheets.retention_years`
     */
    public function __construct(
        private readonly TimesheetRetentionRepositoryInterface $retention,
        private readonly int $retentionYears = 4,
        ?ClockInterface $clock = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
        $this->clock = $clock ?? new NativeClock();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Timesheets per batch (1-5000).', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $batch  = max(1, min(5000, (int) $input->getOption('batch')));
        $cutoff = $this->clock->now()->modify(sprintf('first day of this month -%d years', $this->retentionYears))->format('Y-m-d');
        $totals = ['timesheets' => 0, 'events' => 0, 'ledger' => 0];
        do {
            $done = $this->retention->purgeBefore($cutoff, $batch);
            foreach ($done as $key => $count) {
                $totals[$key] += $count;
            }
        } while ($done['timesheets'] === $batch);

        $this->logger->info('TimeTrack timesheet purge finished.', ['bundle' => 'time_track', 'action' => 'timesheets_purge'] + $totals);
        $io->success(sprintf('Purged %d timesheet(s), %d audit row(s) and %d ledger line(s) before %s.', $totals['timesheets'], $totals['events'], $totals['ledger'], $cutoff));

        return Command::SUCCESS;
    }
}
