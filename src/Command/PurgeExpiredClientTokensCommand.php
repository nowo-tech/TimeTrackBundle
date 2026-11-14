<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Command;

use Nowo\TimeTrackBundle\Monitoring\MonitoringSettings;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DeviceAuthorizationRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\MonitoringUploadIntentRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

/**
 * Deletes the expired client Bearer tokens and device authorization requests (run from cron).
 */
#[AsCommand(
    name: 'nowo:time-track:client-tokens:purge',
    description: 'Remove expired browser extension and desktop client Bearer tokens.',
)]
final class PurgeExpiredClientTokensCommand extends Command
{
    private readonly LoggerInterface $logger;

    private readonly ClockInterface $clock;

    /**
     * Receives the token repository and the logger.
     *
     * @param ClientTokenRepositoryInterface $tokenRepository Store of the hashed tokens
     * @param LoggerInterface|null $logger Operational log (defaults to no logging)
     * @param DeviceAuthorizationRepositoryInterface|null $deviceRepository Store of the device requests (1.9)
     * @param MonitoringUploadIntentRepositoryInterface|null $monitoringIntents Since 1.26: screenshot upload intents, deleted 24 h after creation
     * @param ClockInterface|null $clock Since 1.26: reference time of the intent purge
     */
    public function __construct(
        private readonly ClientTokenRepositoryInterface $tokenRepository,
        ?LoggerInterface $logger = null,
        private readonly ?DeviceAuthorizationRepositoryInterface $deviceRepository = null,
        private readonly ?MonitoringUploadIntentRepositoryInterface $monitoringIntents = null,
        ?ClockInterface $clock = null,
    ) {
        parent::__construct();
        $this->logger = $logger ?? new NullLogger();
        $this->clock  = $clock ?? new NativeClock();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $context = ['bundle' => 'time_track', 'action' => 'client_tokens_purge'];
        $this->logger->info('TimeTrack expired client token purge started.', $context);

        try {
            $purged  = $this->tokenRepository->purgeExpired();
            $devices = $this->deviceRepository?->purgeExpired() ?? 0;
            // Since 1.26: monitoring upload intents past their 24 h replay window (no image is ever stored there).
            $intents = $this->monitoringIntents?->purgeCreatedBefore($this->clock->now()->modify('-' . MonitoringSettings::INTENT_RETENTION_SECONDS . ' seconds')) ?? 0;
        } catch (Throwable $e) {
            $this->logger->error('TimeTrack expired client token purge failed.', $context + ['exception_class' => $e::class]);

            throw $e;
        }

        $this->logger->info('TimeTrack expired client token purge finished.', $context + ['purged' => $purged, 'purged_device_codes' => $devices, 'purged_monitoring_intents' => $intents]);
        $io->success(sprintf('Purged %d expired client token(s) and %d expired device code(s).', $purged, $devices));

        return Command::SUCCESS;
    }
}
