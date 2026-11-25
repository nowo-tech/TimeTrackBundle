<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Since 2.1.0 (Beacon spec 256): the configured {@see ActivityTypeProviderInterface} (none = activity types off), asked
 * deny by default: a provider exception (also a list it built with more than 500 types or a duplicate key) is logged with
 * its class only and read as an empty list. Never memoised (worker mode).
 *
 * @internal
 */
final readonly class ActivityTypeCatalog
{
    private LoggerInterface $logger;

    /**
     * Receives the configured provider, or null.
     */
    public function __construct(private ?ActivityTypeProviderInterface $provider = null, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether an activity type provider is configured.
     */
    public function isEnabled(): bool
    {
        return $this->provider instanceof ActivityTypeProviderInterface;
    }

    /**
     * The tenant's activity types (empty without a provider or when it fails).
     *
     * @param string|null $scopeKey The tenant scope (null = none, asked as '')
     */
    public function listFor(?string $scopeKey): ActivityTypeList
    {
        if (!$this->provider instanceof ActivityTypeProviderInterface) {
            return ActivityTypeList::empty();
        }

        try {
            return $this->provider->activityTypesFor($scopeKey ?? '');
        } catch (Throwable $exception) {
            // REQ-OBS-001: the exception class only.
            $this->logger->warning('TimeTrack activity type provider failed; no activity type is accepted.', ['bundle' => 'time_track', 'action' => 'activity_types', 'exception' => $exception::class]);

            return ActivityTypeList::empty();
        }
    }
}
