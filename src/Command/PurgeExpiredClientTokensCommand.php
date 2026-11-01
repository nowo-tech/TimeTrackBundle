<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

/**
 * Deletes the expired client Bearer tokens (run from cron).
 */
#[AsCommand(
    name: 'nowo:time-track:client-tokens:purge',
    description: 'Remove expired browser extension and desktop client Bearer tokens.',
)]
final class PurgeExpiredClientTokensCommand extends Command
{
    private readonly LoggerInterface $logger;

    /**
     * Receives the token repository and the logger.
     *
     * @param ClientTokenRepositoryInterface $tokenRepository Store of the hashed tokens
     * @param LoggerInterface|null $logger Operational log (defaults to no logging)
     */
    public function __construct(
        private readonly ClientTokenRepositoryInterface $tokenRepository,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
        $this->logger = $logger ?? new NullLogger();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $context = ['bundle' => 'time_track', 'action' => 'client_tokens_purge'];
        $this->logger->info('TimeTrack expired client token purge started.', $context);

        try {
            $purged = $this->tokenRepository->purgeExpired();
        } catch (Throwable $e) {
            $this->logger->error('TimeTrack expired client token purge failed.', $context + ['exception_class' => $e::class]);

            throw $e;
        }

        $this->logger->info('TimeTrack expired client token purge finished.', $context + ['purged' => $purged]);
        $io->success(sprintf('Purged %d expired client token(s).', $purged));

        return Command::SUCCESS;
    }
}
