<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Meeting;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function max;
use function min;
use function str_starts_with;

/**
 * Since 1.14 (contract §22.8): what `GET me` says about meetings — `features.meetings` (enabled, a provider, and the
 * host does not say `disabled`), `meetings: {status, refreshAfterSeconds}` (null when off) and `links.calendars` (the
 * host's https *Calendars* page, or null). A failing provider counts as off for `GET me`.
 */
final readonly class MeetingContext
{
    /** Bounds of the poll-gap hint, in seconds. */
    public const MIN_REFRESH_SECONDS = 60;

    public const MAX_REFRESH_SECONDS = 3600;

    private LoggerInterface $logger;

    /**
     * Receives the settings, the host provider and the URL generator of `links.calendars`.
     */
    public function __construct(
        private MeetingSettings $settings,
        private ?MeetingProviderInterface $provider = null,
        private ?UrlGeneratorInterface $urlGenerator = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The host provider when the feature is configured (null = route 404).
     */
    public function provider(): ?MeetingProviderInterface
    {
        return $this->settings->enabled ? $this->provider : null;
    }

    /**
     * `GET me.meetings`: `{status, refreshAfterSeconds}`, or null when the feature is off for the user.
     *
     * @return array{status: string, refreshAfterSeconds: int}|null
     */
    public function summary(UserInterface $user): ?array
    {
        $provider = $this->provider();
        if (!$provider instanceof MeetingProviderInterface) {
            return null;
        }
        try {
            $state = $provider->stateFor($user);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack meeting provider failed.', ['bundle' => 'time_track', 'action' => 'client_me', 'exception' => $exception::class]);

            return null;
        }

        return $state->status === MeetingSyncStatus::Disabled ? null : ['status' => $state->status->value, 'refreshAfterSeconds' => $this->refreshAfter($state)];
    }

    /**
     * The poll-gap hint of a state, bounded to 60-3600 seconds.
     */
    public function refreshAfter(MeetingSyncState $state): int
    {
        return min(self::MAX_REFRESH_SECONDS, max(self::MIN_REFRESH_SECONDS, $state->refreshAfterSeconds ?? $this->settings->refreshAfterSeconds));
    }

    /**
     * `GET me.links.calendars`: the absolute https URL of the host route, or null.
     */
    public function calendarsLink(): ?string
    {
        $route = $this->settings->calendarsRoute;
        if ($route === null || !$this->urlGenerator instanceof UrlGeneratorInterface) {
            return null;
        }
        try {
            $url = $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (Throwable) {
            return null;
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }
}
