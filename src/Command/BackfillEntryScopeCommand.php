<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use Nowo\TimeTrackBundle\Repository\ScopeBackfillRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function end;
use function is_string;
use function max;
use function sprintf;
use function strlen;

/**
 * Since 1.13: gives pre-1.13 entries their tenant scope (contract §3.1). Keyset on the entry id, the scope provider is
 * asked once per batch ({@see TimesheetScopeProviderInterface::scopeKeysForEntries()}), idempotent and resumable
 * (only entries without a scope are read). Entries the provider cannot place stay without a scope: they belong to no
 * timesheet and show as *unassigned* to their owner. Entries in a locked or submitted period of their owner (any
 * scope) are left untouched and counted apart: giving them a scope would change an approved timesheet after the fact.
 */
#[AsCommand(
    name: 'nowo:time-track:entries:backfill-scope',
    description: 'Set the tenant scope (timesheets) of time entries recorded before 1.13.',
)]
final class BackfillEntryScopeCommand extends Command
{
    private readonly LoggerInterface $logger;

    /**
     * @param TimeEntryRepositoryInterface $repository Entry store (must implement {@see ScopeBackfillRepositoryInterface})
     * @param TimesheetScopeProviderInterface|null $scopeProvider Host scope provider (null = timesheets off)
     * @param PeriodLockGuard|null $lockGuard Skips entries of locked / submitted periods (null = no lock check)
     */
    public function __construct(
        private readonly TimeEntryRepositoryInterface $repository,
        private readonly ?TimesheetScopeProviderInterface $scopeProvider = null,
        ?LoggerInterface $logger = null,
        private readonly ?PeriodLockGuard $lockGuard = null,
    ) {
        parent::__construct();
        $this->logger = $logger ?? new NullLogger();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Entries per batch (1-5000).', '500')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would change, write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->scopeProvider instanceof TimesheetScopeProviderInterface || !$this->repository instanceof ScopeBackfillRepositoryInterface) {
            $io->error('Timesheets are off (no scope provider) or the entry store cannot back-fill scopes.');

            return Command::FAILURE;
        }

        $batch  = max(1, min(5000, (int) $input->getOption('batch')));
        $dryRun = (bool) $input->getOption('dry-run');
        $after  = '';
        $placed = 0;
        $left   = 0;
        $locked = 0;
        do {
            $entries = $this->repository->findScopelessAfter($after, $batch);
            if ($entries === []) {
                break;
            }
            $last  = end($entries);
            $after = $last->getId();
            $keys  = [];
            foreach ($this->scopeProvider->scopeKeysForEntries($entries) as $entryId => $scopeKey) {
                if (is_string($scopeKey) && strlen($scopeKey) <= TimesheetScope::MAX_KEY_LENGTH) {
                    $keys[(string) $entryId] = $scopeKey;
                }
            }
            $left += count($entries) - count($keys);
            foreach ($entries as $entry) {
                if (isset($keys[$entry->getId()]) && $this->lockGuard instanceof PeriodLockGuard && !$this->lockGuard->isEntryWritable($entry)) {
                    unset($keys[$entry->getId()]);
                    ++$locked;
                }
            }
            $placed += $dryRun ? count($keys) : ($keys === [] ? 0 : $this->repository->assignScopeKeys($keys));
        } while (count($entries) === $batch);

        $this->logger->info('TimeTrack entry scope back-fill finished.', ['bundle' => 'time_track', 'action' => 'entries_backfill_scope', 'placed' => $placed, 'unplaced' => $left, 'locked' => $locked, 'dry_run' => $dryRun]);
        $io->success(sprintf('%s %d entr%s; %d left without a scope; %d left in a locked or submitted period.', $dryRun ? 'Would place' : 'Placed', $placed, $placed === 1 ? 'y' : 'ies', $left, $locked));

        return Command::SUCCESS;
    }
}
