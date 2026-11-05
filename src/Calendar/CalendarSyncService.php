<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeZone;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionStatus;
use Nowo\TimeTrackBundle\Event\CalendarSyncFinishedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\CalendarConnectionRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarRuleRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\CalendarSuggestionRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function str_replace;
use function substr;

/**
 * Since 1.14 (contract §6.3–§6.7): syncs server-mode calendar connections. Per connection: a short-lived token from
 * the host, the bundle adapter of the provider (primary calendar, `[now − lookback_days, now]`), the matcher, upserts
 * by `event_ref` (dismissed / accepted suggestions keep their state), grouping of dailies, purge of expired rows and
 * `last_sync_at`. A failure sets `status = error` and an error **code**. Logs carry counts and codes only.
 *
 * The scheduled run ({@see self::syncDue()}) reads a calendar only inside its owner's working hours (AC-19); an
 * on-demand refresh by the owner ({@see self::syncUser()}) is the owner's own action and is not limited.
 */
final readonly class CalendarSyncService
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * @param CalendarEventMatcher|null $matcher Matcher (null = no task-key resolver: calendar off)
     * @param iterable<CalendarEventSourceInterface> $sources Provider adapters (Google, Microsoft)
     * @param CalendarCredentialProviderInterface|null $credentials Host tokens (null = server mode off)
     */
    public function __construct(
        private CalendarConnectionRepositoryInterface $connections,
        private CalendarSuggestionRepositoryInterface $suggestions,
        private CalendarRuleRepositoryInterface $rules,
        private CalendarSettings $settings,
        private CalendarWorkingHours $workingHours,
        private ?CalendarEventMatcher $matcher = null,
        private iterable $sources = [],
        private ?CalendarCredentialProviderInterface $credentials = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether calendar suggestions are on (enabled, with a task-key resolver).
     */
    public function isEnabled(): bool
    {
        return $this->settings->enabled && $this->matcher instanceof CalendarEventMatcher;
    }

    /**
     * Syncs the due connections (never synced, or not within `sync_interval_minutes`) whose owners are inside their
     * working hours, then purges every expired suggestion.
     *
     * @return array{synced: int, failed: int, outside_hours: int, purged: int}
     */
    public function syncDue(?string $userId = null, int $limit = 200): array
    {
        $now    = $this->clock->now();
        $counts = ['synced' => 0, 'failed' => 0, 'outside_hours' => 0, 'purged' => 0];
        if ($this->isEnabled()) {
            $before = $now->modify('-' . $this->settings->syncIntervalMinutes . ' minutes');
            foreach ($this->connections->findConnectionsDue($before, $userId, $limit) as $connection) {
                $owner = $connection->getUser();
                if (!$owner instanceof UserInterface || !$this->workingHours->contains($owner, $now)) {
                    ++$counts['outside_hours'];
                    continue;
                }
                ++$counts[$this->sync($connection)->isSuccess() ? 'synced' : 'failed'];
            }
        }
        $counts['purged'] = $this->suggestions->deleteExpiredSuggestions($now);
        $this->logger->info('TimeTrack calendar sync run finished.', ['bundle' => 'time_track', 'action' => 'calendar_sync'] + $counts);

        return $counts;
    }

    /**
     * On-demand refresh by the owner: syncs the user's server-mode connections in the scope (all scopes when null).
     *
     * @return list<CalendarSyncResult>
     */
    public function syncUser(UserInterface $user, ?string $scopeKey): array
    {
        $results = [];
        foreach ($this->connections->findConnectionsByUser(UserIdResolver::getId($user), $scopeKey) as $connection) {
            if ($connection->getMode() === CalendarConnectionMode::Server && $connection->getStatus() !== CalendarConnectionStatus::Paused) {
                $results[] = $this->sync($connection);
            }
        }

        return $results;
    }

    /**
     * Syncs one server-mode connection.
     */
    public function sync(CalendarConnection $connection): CalendarSyncResult
    {
        $now     = $this->clock->now();
        $counts  = ['fetched' => 0, 'matched' => 0, 'ignored' => 0, 'new' => 0, 'updated' => 0, 'expired' => 0];
        $owner   = $connection->getUser();
        $matcher = $this->matcher;
        if (!$matcher instanceof CalendarEventMatcher || !$this->settings->enabled || !$owner instanceof UserInterface || $connection->getMode() !== CalendarConnectionMode::Server
            || !$this->settings->allowsProvider($connection->getProvider())) {
            return new CalendarSyncResult($connection->getId(), $counts, 'not_syncable');
        }

        try {
            $events = $this->source($connection)->fetch($this->credential($owner, $connection), $now->modify('-' . $this->settings->lookbackDays . ' days'), $now);
            $result = $matcher->match($owner, $connection, $events, $this->rules->findRulesByUser(UserIdResolver::getId($owner), $connection->getScopeKey()), $now);
        } catch (CalendarSyncException $exception) {
            $connection->markFailed($exception->errorCode, $now);
            $this->connections->saveConnection($connection);

            return $this->finished($connection, $counts, $exception->errorCode);
        }

        $counts['fetched'] = $result['fetched'];
        $counts['ignored'] = $result['ignored'];
        $counts['matched'] = count($result['matches']);
        $timezone          = $this->workingHours->timezone($owner);
        foreach ($result['matches'] as $match) {
            $groupKey  = $this->groupKey($match, $timezone);
            $expiresAt = $match->end->modify('+' . $this->settings->suggestionTtlDays . ' days');
            $existing  = $this->suggestions->findSuggestionByRef($connection->getId(), $match->eventRef);
            if ($existing instanceof CalendarSuggestion) {
                $counts['updated'] += $existing->refresh($match->taskId, $match->kind, $groupKey, $match->start, $match->end, $expiresAt) ? 1 : 0;
                continue;
            }
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->suggestions->addSuggestion(new CalendarSuggestion($connection, $owner, $match->eventRef, $match->seriesRef, $groupKey, $match->taskId, $match->kind, $match->start, $match->end, $expiresAt, $now));
            ++$counts['new'];
        }
        $connection->markSynced($now);
        $this->connections->saveConnection($connection);
        $counts['expired'] = $this->suggestions->deleteExpiredSuggestions($now, $connection->getId());

        return $this->finished($connection, $counts, null);
    }

    /**
     * The group of a match (contract §6.4): `series:<series_ref[0..16]>:<ISO week>` for a recurring event matched by
     * key, `rule:<rule id hex>:<ISO week>` for a grouping rule, null otherwise. The week is the user's local one.
     */
    public function groupKey(CalendarMatch $match, DateTimeZone $timezone): ?string
    {
        if (!$match->grouped) {
            return null;
        }
        $week = $match->start->setTimezone($timezone)->format('o-\WW');

        return $match->ruleId !== null
            ? 'rule:' . str_replace('-', '', $match->ruleId) . ':' . $week
            : 'series:' . substr((string) $match->seriesRef, 0, 16) . ':' . $week;
    }

    /**
     * @throws CalendarSyncException
     */
    private function source(CalendarConnection $connection): CalendarEventSourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source->provider() === $connection->getProvider()) {
                return $source;
            }
        }

        throw new CalendarSyncException(CalendarSyncException::NO_SOURCE);
    }

    /**
     * @throws CalendarSyncException
     */
    private function credential(UserInterface $owner, CalendarConnection $connection): CalendarCredential
    {
        $credential = $this->credentials?->credentialFor($owner, $connection);
        if (!$credential instanceof CalendarCredential) {
            throw new CalendarSyncException(CalendarSyncException::NO_CREDENTIAL);
        }

        return $credential;
    }

    /**
     * @param array{fetched: int, matched: int, ignored: int, new: int, updated: int, expired: int} $counts
     */
    private function finished(CalendarConnection $connection, array $counts, ?string $errorCode): CalendarSyncResult
    {
        $this->eventDispatcher?->dispatch(new CalendarSyncFinishedEvent($connection->getId(), $counts, $errorCode), TimeTrackEvents::CALENDAR_SYNC_FINISHED);
        $this->logger->info('TimeTrack calendar connection synced.', ['bundle' => 'time_track', 'action' => 'calendar_sync', 'provider' => $connection->getProvider()->value, 'error' => $errorCode] + $counts);

        return new CalendarSyncResult($connection->getId(), $counts, $errorCode);
    }
}
