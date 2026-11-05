<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use Nowo\TimeTrackBundle\Calendar\CalendarSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * Since 1.14 (contract §6.7): syncs the server-mode calendar connections that are due (`calendar.sync_interval_minutes`)
 * and whose owners are inside their working hours, then purges the expired suggestions. The host schedules it (e.g.
 * every 15 minutes). Prints counts only.
 */
#[AsCommand(
    name: 'nowo:time-track:calendar:sync',
    description: 'Sync due calendar connections (inside working hours) and purge expired suggestions.',
)]
final class CalendarSyncCommand extends Command
{
    /**
     * Receives the sync service.
     */
    public function __construct(private readonly CalendarSyncService $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only this user id.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Connections per run (1-5000).', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->sync->isEnabled()) {
            $io->note('Calendar suggestions are disabled (calendar.enabled with a task_key_resolver); purging expired suggestions only.');
        }
        $user   = $input->getOption('user');
        $counts = $this->sync->syncDue(is_string($user) && $user !== '' ? $user : null, max(1, min(5000, (int) $input->getOption('limit'))));
        $io->success(sprintf('Synced %d connection(s), %d failed, %d outside working hours; purged %d expired suggestion(s).', $counts['synced'], $counts['failed'], $counts['outside_hours'], $counts['purged']));

        return Command::SUCCESS;
    }
}
