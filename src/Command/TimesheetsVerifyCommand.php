<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use Nowo\TimeTrackBundle\Timesheet\AuditChainVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;

/**
 * Since 1.13: recomputes every timesheet audit chain and ledger chain and reports the first broken link of each
 * (exit code 1 when one is broken).
 */
#[AsCommand(
    name: 'nowo:time-track:timesheets:verify',
    description: 'Verify the hash chains of the timesheet audit trail and the balance ledger.',
)]
final class TimesheetsVerifyCommand extends Command
{
    /**
     * Receives the verifier.
     */
    public function __construct(private readonly AuditChainVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Only this scope key.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only this owner id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $scope    = $input->getOption('scope');
        $user     = $input->getOption('user');
        $problems = $this->verifier->verify(is_string($scope) ? $scope : null, is_string($user) ? $user : null);
        if ($problems !== []) {
            $io->error($problems);

            return Command::FAILURE;
        }
        $io->success('Every audit chain is intact.');

        return Command::SUCCESS;
    }
}
