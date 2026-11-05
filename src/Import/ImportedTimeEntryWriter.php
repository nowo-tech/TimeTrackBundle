<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function mb_check_encoding;
use function mb_substr;
use function preg_match;
use function preg_replace;

/**
 * Since 1.11: writes imported time entries ({@see TimeEntrySource::Import}), idempotently by
 * (externalSource, externalId). It never starts or stops a timer and never fires timer events; it never moves an entry
 * to another owner (remove, then upsert). The host authorises every call (who may import or delete).
 *
 * Owner rule (2026-11-05): deleting tasks or boards never deletes tracked time. `remove*()` are for an explicit
 * manager action on imported time (and `removeForUser()` for account erasure), never a side effect of deleting a task
 * or a board.
 */
final readonly class ImportedTimeEntryWriter
{
    public const SOURCE_PATTERN = '/^[a-z0-9_]{1,24}:[A-Za-z0-9._-]{1,39}$/D';

    public const ID_PATTERN = '/^[A-Za-z0-9._:#-]{1,128}$/D';

    public const TASK_ID_PATTERN = '/^[A-Za-z0-9._-]{1,36}$/D';

    /** Default longest single imported entry: 31 days. */
    public const DEFAULT_MAX_ENTRY_SECONDS = 2_678_400;

    /** Longest stored task title snapshot, in code points (the column length). */
    public const MAX_TITLE_LENGTH = 255;

    /** The most rows one {@see self::upsertMany()} call takes. */
    public const MAX_BATCH = 500;

    /** Tolerance between the duration and the span start → end (the source rounds). */
    private const SPAN_TOLERANCE_SECONDS = 60;

    private ImportedTimeEntryRepositoryInterface $repository;

    /**
     * Creates the object.
     */
    public function __construct(
        TimeEntryRepositoryInterface $repository,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private int $maxEntrySeconds = self::DEFAULT_MAX_ENTRY_SECONDS,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if (!$repository instanceof ImportedTimeEntryRepositoryInterface) {
            throw new LogicException('Imported time entries require a repository implementing ImportedTimeEntryRepositoryInterface.');
        }
        $this->repository = $repository;
    }

    /**
     * Creates or updates the entry of the external identity; an unchanged entry is not written and fires nothing.
     *
     * @throws InvalidImportedTimeEntryException when the data breaks a rule (nothing written)
     * @throws ImportedEntryOwnerMismatchException when the entry exists for another user (nothing written)
     */
    public function upsert(UserInterface $user, ImportedTimeEntryData $data): ImportedTimeEntryResult
    {
        return $this->upsertMany([[$user, $data]])->results[0];
    }

    /**
     * Upserts up to {@see self::MAX_BATCH} rows with one flush. Every row is validated (and the owners checked) before
     * anything is written: one bad row refuses the whole batch, and so do two rows for one external identity
     * (`rows` / `duplicate`). When a concurrent run inserts one of the identities first (unique violation on flush,
     * nothing written), the whole batch is read again once when the store allows it
     * ({@see ImportedTimeEntryRepositoryInterface::canRetryAfterFailedSave()}: the Doctrine store needs a
     * `ManagerRegistry` and no open host transaction): that row becomes an update and the new rows are created with
     * their owners re-attached. Otherwise the unique violation is rethrown and the host retries the slice.
     *
     * @param list<array{0: UserInterface, 1: ImportedTimeEntryData}> $rows
     */
    public function upsertMany(array $rows): ImportedTimeEntryBatchResult
    {
        if (count($rows) > self::MAX_BATCH) {
            throw new InvalidImportedTimeEntryException('rows', 'max_batch');
        }
        $valid = [];
        $keys  = [];
        foreach ($rows as [$user, $data]) {
            $data = $this->validate($data);
            $key  = $data->externalSource . "\0" . $data->externalId;
            if (isset($keys[$key])) {
                // Two rows for one external identity would insert it twice (unique violation): the host must send one.
                throw new InvalidImportedTimeEntryException('rows', 'duplicate');
            }
            $keys[$key] = true;
            $valid[]    = [$user, $data];
        }

        try {
            return $this->write($valid);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent run inserted one of these identities between our read and our flush. Nothing was written
            // (the flush rolled back). Without a usable store (no registry reset it, or a host transaction is still
            // open) fail closed: the host retries the slice. Otherwise read again, once: the raced identity is an
            // update (or unchanged) now, with the owner check applied to the stored row, and the new rows reference
            // owners re-attached to the reset store.
            if (!$this->repository->canRetryAfterFailedSave()) {
                throw $exception;
            }
            $retry = [];
            foreach ($valid as [$user, $data]) {
                $retry[] = [$this->repository->attachUser($user), $data];
            }

            return $this->write($retry);
        }
    }

    /**
     * @param list<array{0: UserInterface, 1: ImportedTimeEntryData}> $rows validated rows
     */
    private function write(array $rows): ImportedTimeEntryBatchResult
    {
        $plan = [];
        foreach ($rows as [$user, $data]) {
            $existing = $this->repository->findByExternal($data->externalSource, $data->externalId);
            if ($existing instanceof TimeEntry && !$this->sameOwner($existing, $user)) {
                throw new ImportedEntryOwnerMismatchException();
            }
            $plan[] = [$user, $data, $existing];
        }

        $results = [];
        $write   = [];
        $events  = [];
        $counts  = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($plan as [$user, $data, $existing]) {
            if (!$existing instanceof TimeEntry) {
                $entry     = TimeEntry::imported($user, $data);
                $write[]   = $entry;
                $results[] = new ImportedTimeEntryResult($entry, ImportedTimeEntryOutcome::Created);
                $events[]  = new TimeEntryImportedEvent($entry, ImportedTimeEntryOutcome::Created);
                ++$counts['created'];
                continue;
            }
            $previousSeconds = $existing->getDurationSeconds();
            $previousTask    = $existing->getTaskId();
            if (!$existing->applyImport($data)) {
                $results[] = new ImportedTimeEntryResult($existing, ImportedTimeEntryOutcome::Unchanged);
                ++$counts['unchanged'];
                continue;
            }
            $write[]   = $existing;
            $results[] = new ImportedTimeEntryResult($existing, ImportedTimeEntryOutcome::Updated);
            $events[]  = new TimeEntryImportedEvent($existing, ImportedTimeEntryOutcome::Updated, $previousSeconds, $previousTask !== $existing->getTaskId() ? $previousTask : null);
            ++$counts['updated'];
        }

        if ($write !== []) {
            // @igor-ignore - I/O delegate (repository / writer call); no worker state.
            $this->repository->saveAll($write);
        }
        foreach ($events as $event) {
            $this->eventDispatcher?->dispatch($event, TimeTrackEvents::TIME_ENTRY_IMPORTED);
        }
        if ($write !== []) {
            $this->logger->info('TimeTrack imported entries written.', ['bundle' => 'nowo_time_track', 'created' => $counts['created'], 'updated' => $counts['updated'], 'unchanged' => $counts['unchanged']]);
        }

        return new ImportedTimeEntryBatchResult($results, $counts['created'], $counts['updated'], $counts['unchanged']);
    }

    /** Removes one imported entry; returns the count (0 or 1). */
    public function remove(string $externalSource, string $externalId, ?string $actorId = null): int
    {
        return $this->removeScope(ImportedTimeEntryScope::entry($this->checkSource($externalSource), $externalId), $actorId);
    }

    /** Removes every imported entry of the source owned by the user (account erasure, re-association). */
    public function removeForUser(string $externalSource, UserInterface $user): int
    {
        return $this->removeScope(ImportedTimeEntryScope::user($this->checkSource($externalSource), UserIdResolver::getId($user)), null);
    }

    /**
     * Removes the imported entries of the source tracked on these tasks: an explicit manager action on imported time
     * only. Never call it from a task deletion (deleting tasks keeps all tracked time).
     *
     * @param list<string> $taskIds
     */
    public function removeForTasks(string $externalSource, array $taskIds): int
    {
        return $this->removeScope(ImportedTimeEntryScope::tasks($this->checkSource($externalSource), $taskIds), null);
    }

    /**
     * Removes the imported entries of the source whose board snapshot is this board: an explicit manager action on
     * imported time only. Never call it from a board deletion (deleting boards keeps all tracked time).
     */
    public function removeForBoard(string $externalSource, string $boardId): int
    {
        return $this->removeScope(ImportedTimeEntryScope::board($this->checkSource($externalSource), $boardId), null);
    }

    /** Removes every imported entry of exactly this source (never a prefix match). */
    public function removeForSource(string $externalSource): int
    {
        return $this->removeScope(ImportedTimeEntryScope::source($this->checkSource($externalSource)), null);
    }

    /**
     * The data the writer stores: patterns, span and duration checked, valid UTF-8 required, title cleaned to one line
     * and cut to 255, description cleaned (kept in full, never truncated).
     *
     * @throws InvalidImportedTimeEntryException
     */
    public function validate(ImportedTimeEntryData $data): ImportedTimeEntryData
    {
        $this->checkSource($data->externalSource);
        if (preg_match(self::ID_PATTERN, $data->externalId) !== 1) {
            throw new InvalidImportedTimeEntryException('externalId', 'pattern');
        }
        if (preg_match(self::TASK_ID_PATTERN, $data->taskId) !== 1) {
            throw new InvalidImportedTimeEntryException('taskId', 'pattern');
        }
        if ($data->boardId !== null && preg_match(self::TASK_ID_PATTERN, $data->boardId) !== 1) {
            throw new InvalidImportedTimeEntryException('boardId', 'pattern');
        }
        $span = $data->endedAt->getTimestamp() - $data->startedAt->getTimestamp();
        if ($span < 0) {
            throw new InvalidImportedTimeEntryException('endedAt', 'before_start');
        }
        if ($data->durationSeconds <= 0) {
            throw new InvalidImportedTimeEntryException('durationSeconds', 'not_positive');
        }
        if ($data->durationSeconds > $span + self::SPAN_TOLERANCE_SECONDS) {
            throw new InvalidImportedTimeEntryException('durationSeconds', 'longer_than_span');
        }
        if ($data->durationSeconds > $this->maxEntrySeconds || $span > $this->maxEntrySeconds) {
            throw new InvalidImportedTimeEntryException('durationSeconds', 'max_entry_seconds');
        }
        if (!mb_check_encoding($data->taskTitle, 'UTF-8')) {
            throw new InvalidImportedTimeEntryException('taskTitle', 'encoding');
        }
        if ($data->description !== null && !mb_check_encoding($data->description, 'UTF-8')) {
            throw new InvalidImportedTimeEntryException('description', 'encoding');
        }
        // The title is one line: the description rules (controls and bidi overrides stripped), then every run of line
        // breaks (LF, U+2028 line separator, U+2029 paragraph separator) and the blanks around it becomes one space.
        $title = (string) preg_replace('/\h*[\n\x{2028}\x{2029}][\n\x{2028}\x{2029}\h]*/u', ' ', ImportedDescriptionSanitizer::clean($data->taskTitle) ?? '');

        return new ImportedTimeEntryData(
            $data->externalSource,
            $data->externalId,
            $data->taskId,
            mb_substr($title === '' ? $data->taskId : $title, 0, self::MAX_TITLE_LENGTH),
            $data->boardId,
            $data->startedAt,
            $data->endedAt,
            $data->durationSeconds,
            $data->billable,
            ImportedDescriptionSanitizer::clean($data->description),
        );
    }

    private function removeScope(ImportedTimeEntryScope $scope, ?string $actorId): int
    {
        // @igor-ignore - I/O delegate (repository / writer call); no worker state.
        $removal = $this->repository->removeImported($scope);
        if ($removal->count > 0) {
            $this->eventDispatcher?->dispatch(new TimeEntriesRemovedEvent($scope->externalSource, $scope->kind, $removal, $actorId), TimeTrackEvents::TIME_ENTRIES_REMOVED);
            $this->logger->info('TimeTrack imported entries removed.', ['bundle' => 'nowo_time_track', 'scope' => $scope->kind, 'count' => $removal->count]);
        }

        return $removal->count;
    }

    private function sameOwner(TimeEntry $entry, UserInterface $user): bool
    {
        $owner = $entry->getUser();

        return $owner instanceof UserInterface && UserIdResolver::getId($owner) === UserIdResolver::getId($user);
    }

    private function checkSource(string $externalSource): string
    {
        if (preg_match(self::SOURCE_PATTERN, $externalSource) !== 1) {
            throw new InvalidImportedTimeEntryException('externalSource', 'pattern');
        }

        return $externalSource;
    }
}
