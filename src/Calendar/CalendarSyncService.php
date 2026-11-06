<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
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
use Throwable;

use function count;
use function max;
use function min;
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
    /** Code of a connection the configuration does not sync (provider no longer enabled, local mode, feature off). */
    public const NOT_SYNCABLE = 'not_syncable';

    /** Code of an unexpected failure (host, store or listener exception). */
    public const INTERNAL_ERROR = 'internal_error';

    /** Longest backoff after failures, in minutes (a day). */
    public const MAX_BACKOFF_MINUTES = 1440;

    /** Candidate pages read per scheduled run (outside-hours rows are skipped). */
    public const MAX_DUE_PAGES = 10;

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
     * Syncs the due connections (never attempted, or whose next attempt has come: `sync_interval_minutes` after a
     * success, an exponential backoff after failures, at most {@see self::MAX_BACKOFF_MINUTES}) whose owners are inside
     * their working hours, least recently attempted first, then purges every expired suggestion. Connections skipped as
     * outside working hours do not count against `$limit`; one failing connection never stops the batch.
     *
     * @return array{synced: int, failed: int, outside_hours: int, purged: int}
     */
    public function syncDue(?string $userId = null, int $limit = 200): array
    {
        $now    = $this->clock->now();
        $limit  = max(1, $limit);
        $counts = ['synced' => 0, 'failed' => 0, 'outside_hours' => 0, 'purged' => 0];
        if ($this->isEnabled()) {
            $offset = 0;
            $seen   = [];
            // Pages of candidates until `$limit` connections were attempted (outside-hours rows are skipped, not counted).
            // Only connections of enabled providers are due (GG-D-1); a row met twice in one run (its next attempt could
            // not be recorded) is skipped like an outside-hours row, so nothing is re-fetched forever.
            for ($page = 0; $page < self::MAX_DUE_PAGES && $limit > $counts['synced'] + $counts['failed']; ++$page) {
                $due = $this->connections->findConnectionsDue($now, $userId, $limit, $offset, $this->settings->providers);
                foreach ($due as $connection) {
                    if (isset($seen[$connection->getId()])) {
                        ++$offset;
                        continue;
                    }
                    $seen[$connection->getId()] = true;
                    $owner                      = $connection->getUser();
                    if (!$owner instanceof UserInterface || !$this->workingHours->contains($owner, $now)) {
                        ++$counts['outside_hours'];
                        ++$offset;
                        continue;
                    }
                    ++$counts[$this->sync($connection)->isSuccess() ? 'synced' : 'failed'];
                    if ($limit === $counts['synced'] + $counts['failed']) {
                        break 2;
                    }
                }
                if (count($due) < $limit) {
                    break;
                }
            }
        }
        $counts['purged'] = $this->suggestions->deleteExpiredSuggestions($now);
        $this->logger->info('TimeTrack calendar sync run finished.', ['bundle' => 'time_track', 'action' => 'calendar_sync'] + $counts);

        return $counts;
    }

    /**
     * On-demand refresh by the owner: syncs the user's server-mode connections in the scope (all scopes when null).
     * Failures come back as results with a code, never as an exception.
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
     * Syncs one server-mode connection. Any failure (a provider code, or an unexpected exception of the host, the store
     * or a listener: `internal_error`) is recorded on the connection with a backoff and returned, never thrown.
     */
    public function sync(CalendarConnection $connection): CalendarSyncResult
    {
        $now     = $this->clock->now();
        $counts  = ['fetched' => 0, 'matched' => 0, 'ignored' => 0, 'new' => 0, 'updated' => 0, 'expired' => 0, 'stale' => 0];
        $owner   = $connection->getUser();
        $matcher = $this->matcher;
        if (!$matcher instanceof CalendarEventMatcher || !$this->settings->enabled || !$owner instanceof UserInterface || $connection->getMode() !== CalendarConnectionMode::Server) {
            return new CalendarSyncResult($connection->getId(), $counts, self::NOT_SYNCABLE);
        }
        if (!$this->settings->allowsProvider($connection->getProvider())) {
            // GG-D-1: a provider removed from `calendar.providers` is recorded with a backoff, never retried at once.
            $this->recordFailure($connection, self::NOT_SYNCABLE, $now);

            return new CalendarSyncResult($connection->getId(), $counts, self::NOT_SYNCABLE);
        }

        try {
            return $this->run($connection, $owner, $matcher, $now, $counts);
        } catch (CalendarSyncException $exception) {
            $code = $exception->errorCode;
        } catch (Throwable $exception) {
            $code = self::INTERNAL_ERROR;
            $this->logger->warning('TimeTrack calendar sync failed.', ['bundle' => 'time_track', 'action' => 'calendar_sync', 'exception' => $exception::class]);
        }
        $this->recordFailure($connection, $code, $now);

        return $this->finished($connection, $counts, $code);
    }

    /**
     * Records a failure and its backoff; a store failure here is logged (class only), never thrown.
     */
    private function recordFailure(CalendarConnection $connection, string $code, DateTimeImmutable $now): void
    {
        try {
            $connection->markFailed($code, $now, $this->backoff($connection, $now));
            $this->connections->saveConnection($connection);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack calendar sync failure could not be recorded.', ['bundle' => 'time_track', 'action' => 'calendar_sync', 'exception' => $exception::class]);
        }
    }

    /**
     * @param array{fetched: int, matched: int, ignored: int, new: int, updated: int, expired: int, stale: int} $counts
     */
    private function run(CalendarConnection $connection, UserInterface $owner, CalendarEventMatcher $matcher, DateTimeImmutable $now, array $counts): CalendarSyncResult
    {
        $from   = $now->modify('-' . $this->settings->lookbackDays . ' days');
        $events = $this->source($connection)->fetch($this->credential($owner, $connection), $from, $now);
        $result = $matcher->match($owner, $connection, $events, $this->rules->findRulesByUser(UserIdResolver::getId($owner), $connection->getScopeKey()), $now);
        // A source that stopped at `max_pages` returns true: its window was not read in full.
        $complete = !$events instanceof Generator || $events->getReturn() !== true;

        $counts['fetched'] = $result['fetched'];
        $counts['ignored'] = $result['ignored'];
        $counts['matched'] = count($result['matches']);
        $timezone          = $this->workingHours->timezone($owner);
        $seen              = [];
        foreach ($result['matches'] as $match) {
            $seen[]    = $match->eventRef;
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
        $connection->markSynced($now, $now->modify('+' . $this->settings->syncIntervalMinutes . ' minutes'));
        $this->connections->saveConnection($connection);
        // GG-L3: pending suggestions of the synced window whose event is gone (moved, cancelled, declined, key removed).
        if ($complete) {
            $counts['stale'] = $this->suggestions->deleteUnseenPending($connection->getId(), $from, $now, $seen);
        }
        $counts['expired'] = $this->suggestions->deleteExpiredSuggestions($now, $connection->getId());

        return $this->finished($connection, $counts, null);
    }

    /**
     * Next attempt after a failure: twice the sync interval, doubled again per failure in a row, at most a day.
     */
    private function backoff(CalendarConnection $connection, DateTimeImmutable $now): DateTimeImmutable
    {
        $minutes = min(self::MAX_BACKOFF_MINUTES, $this->settings->syncIntervalMinutes * 2 ** min(10, $connection->getFailureCount() + 1));

        return $now->modify('+' . $minutes . ' minutes');
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
     * @param array{fetched: int, matched: int, ignored: int, new: int, updated: int, expired: int, stale: int} $counts
     */
    private function finished(CalendarConnection $connection, array $counts, ?string $errorCode): CalendarSyncResult
    {
        $this->eventDispatcher?->dispatch(new CalendarSyncFinishedEvent($connection->getId(), $counts, $errorCode), TimeTrackEvents::CALENDAR_SYNC_FINISHED);
        $this->logger->info('TimeTrack calendar connection synced.', ['bundle' => 'time_track', 'action' => 'calendar_sync', 'provider' => $connection->getProvider()->value, 'error' => $errorCode] + $counts);

        return new CalendarSyncResult($connection->getId(), $counts, $errorCode);
    }
}
