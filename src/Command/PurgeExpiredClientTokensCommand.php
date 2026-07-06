<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:time-track:client-tokens:purge',
    description: 'Remove expired browser extension and desktop client Bearer tokens.',
)]
final class PurgeExpiredClientTokensCommand extends Command
{
    public function __construct(
        private readonly ClientTokenRepositoryInterface $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $purged = $this->tokenRepository->purgeExpired();
        $io->success(sprintf('Purged %d expired client token(s).', $purged));

        return Command::SUCCESS;
    }
}
