<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Repository\FilteredImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function mb_check_encoding;
use function mb_substr;
use function preg_match;
use function str_starts_with;
use function strlen;

/**
 * Since 1.11: writes imported time entries ({@see TimeEntrySource::Import}), idempotently by
 * (externalSource, externalId). It never starts or stops a timer and never fires timer events; it never moves an entry
 * to another owner (remove, then upsert). The host authorises every call (who may import or delete).
 *
 * Since 1.15 a row may name a person with no user account instead of a user ({@see ImportedTimeEntryPerson}): the entry
 * has no owner and shows under the person's name. A later upsert naming a user claims it (nobody owned it, so nothing
 * moves between users), unless it is anonymised: an anonymised entry is never written again (`Unchanged`), so a re-sync
 * cannot bring a name back.
 *
 * Since 1.13 (timesheets on): every written entry gets the tenant scope of its task, and a row whose entry is (or
 * would move) into a locked or submitted period is skipped with {@see ImportedTimeEntryOutcome::Locked} (contract
 * Q-O13); removals never delete an entry of such a period ({@see self::remove()} refuses, the bulk removals skip it),
 * except {@see self::removeForUser()}.
 *
 * Owner rule (2026-11-05, widened 2026-11-06): deleting tasks, boards or users never deletes tracked time. `remove*()` are
 * for an explicit manager action on imported time, ownerless entries included (the owner's ruling: "never deleted" means
 * never as a side effect), and `removeForUser()` is for re-association only. Each respects the period lock as stated
 * on it and is recorded by {@see TimeEntriesRemovedEvent} (count, seconds, owners, people, actor) and a log line of counts;
 * the bundle keeps no audit table of its own, so the host stores the event.
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

    /** Since 1.12: earliest year of an imported start (no epoch-0 or pre-1970 dates from a broken source). */
    public const MIN_YEAR = 1970;

    /** Since 1.12: how far after now an imported entry may end (clock skew and time zones of the source). */
    public const MAX_FUTURE_SECONDS = 86_400;

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
        private ?ClockInterface $clock = null,
        private ?PeriodLockGuard $periodLockGuard = null,
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
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
     * Since 1.15: creates or updates the entry of a person with no user account (entry source `import`, no owner).
     *
     * @throws InvalidImportedTimeEntryException when the data or the person break a rule (nothing written)
     * @throws ImportedEntryOwnerMismatchException when the entry exists for a user or for another person (nothing written)
     */
    public function upsertForPerson(ImportedTimeEntryPerson $person, ImportedTimeEntryData $data): ImportedTimeEntryResult
    {
        return $this->upsertMany([[$person, $data]])->results[0];
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
     * Since 1.15 a row may name a person instead of a user ({@see self::upsertForPerson()}).
     *
     * @param list<array{0: ImportedTimeEntryPerson|UserInterface, 1: ImportedTimeEntryData}> $rows
     */
    public function upsertMany(array $rows): ImportedTimeEntryBatchResult
    {
        if (count($rows) > self::MAX_BATCH) {
            throw new InvalidImportedTimeEntryException('rows', 'max_batch');
        }
        $valid = [];
        $keys  = [];
        foreach ($rows as [$owner, $data]) {
            $owner = $owner instanceof ImportedTimeEntryPerson ? $this->validatePerson($owner) : $owner;
            $data  = $this->validate($data);
            $key   = $data->externalSource . "\0" . $data->externalId;
            if (isset($keys[$key])) {
                // Two rows for one external identity would insert it twice (unique violation): the host must send one.
                throw new InvalidImportedTimeEntryException('rows', 'duplicate');
            }
            $keys[$key] = true;
            $valid[]    = [$owner, $data];
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
            foreach ($valid as [$owner, $data]) {
                $retry[] = [$owner instanceof UserInterface ? $this->repository->attachUser($owner) : $owner, $data];
            }

            return $this->write($retry);
        }
    }

    /**
     * @param list<array{0: ImportedTimeEntryPerson|UserInterface, 1: ImportedTimeEntryData}> $rows validated rows
     */
    private function write(array $rows): ImportedTimeEntryBatchResult
    {
        $plan = [];
        foreach ($rows as [$owner, $data]) {
            $existing = $this->repository->findByExternal($data->externalSource, $data->externalId);
            if ($existing instanceof TimeEntry && !$existing->isAnonymised() && !$this->sameOwner($existing, $owner)) {
                throw new ImportedEntryOwnerMismatchException();
            }
            $plan[] = [$owner, $data, $existing];
        }

        $results = [];
        $write   = [];
        $events  = [];
        $counts  = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'locked' => 0];
        foreach ($plan as [$owner, $data, $existing]) {
            $user     = $owner instanceof UserInterface ? $owner : null;
            $scopeKey = $user instanceof UserInterface ? $this->scopeProvider?->scopeKeyForTask($user, $data->taskId, $data->boardId) : null;
            if (!$existing instanceof TimeEntry) {
                $entry = $owner instanceof ImportedTimeEntryPerson ? TimeEntry::importedForPerson($owner, $data) : TimeEntry::imported($user, $data)->assignScopeKey($scopeKey);
                if ($user instanceof UserInterface && $this->isLocked($user, $scopeKey, $data->startedAt)) {
                    $results[] = new ImportedTimeEntryResult($entry, ImportedTimeEntryOutcome::Locked);
                    ++$counts['locked'];
                    continue;
                }
                $write[]   = $entry;
                $results[] = new ImportedTimeEntryResult($entry, ImportedTimeEntryOutcome::Created);
                $events[]  = new TimeEntryImportedEvent($entry, ImportedTimeEntryOutcome::Created);
                ++$counts['created'];
                continue;
            }
            $previousSeconds = $existing->getDurationSeconds();
            $previousTask    = $existing->getTaskId();
            $claimed         = $user instanceof UserInterface && $existing->isOwnerless();
            $personChanges   = $owner instanceof ImportedTimeEntryPerson && $this->personChanges($existing, $owner);
            if ($existing->isAnonymised() || (!$existing->differsFromImport($data) && !$claimed && !$personChanges)) {
                $results[] = new ImportedTimeEntryResult($existing, ImportedTimeEntryOutcome::Unchanged);
                ++$counts['unchanged'];
                continue;
            }
            // The stored entry may not leave a locked period, and the new data may not enter one.
            if ($this->isEntryLocked($existing) || ($user instanceof UserInterface && $this->isLocked($user, $scopeKey, $data->startedAt))) {
                $results[] = new ImportedTimeEntryResult($existing, ImportedTimeEntryOutcome::Locked);
                ++$counts['locked'];
                continue;
            }
            $existing->applyImport($data);
            if ($owner instanceof ImportedTimeEntryPerson) {
                $existing->applyPerson($owner);
            } elseif ($claimed) {
                $existing->claimFor($owner);
            }
            if ($user instanceof UserInterface && $this->scopeProvider instanceof TimesheetScopeProviderInterface) {
                $existing->assignScopeKey($scopeKey);
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
            $this->logger->info('TimeTrack imported entries written.', ['bundle' => 'nowo_time_track', 'created' => $counts['created'], 'updated' => $counts['updated'], 'unchanged' => $counts['unchanged'], 'locked' => $counts['locked']]);
        }

        return new ImportedTimeEntryBatchResult($results, $counts['created'], $counts['updated'], $counts['unchanged'], $counts['locked']);
    }

    /**
     * Since 1.15: whether a stored entry's period refuses the write: through the refusal path while it has a user,
     * by the guard's ownerless rule otherwise.
     */
    private function isEntryLocked(TimeEntry $entry): bool
    {
        $owner = $entry->getUser();
        if ($owner instanceof UserInterface) {
            return $this->isLocked($owner, $entry->getScopeKey(), $entry->getStartedAt());
        }

        return $this->periodLockGuard instanceof PeriodLockGuard && !$this->periodLockGuard->isEntryWritable($entry);
    }

    /** Since 1.15: whether the upsert would change the person of an ownerless entry that belonged to no user. */
    private function personChanges(TimeEntry $entry, ImportedTimeEntryPerson $person): bool
    {
        return $entry->getFormerUserId() === null && ($entry->getPersonRef() !== $person->ref || $entry->getPersonName() !== $person->name);
    }

    /**
     * Since 1.13: whether the period lock refuses a write of the user's time at `$instant` (the refusal event fires).
     */
    private function isLocked(UserInterface $user, ?string $scopeKey, DateTimeImmutable $instant): bool
    {
        return $this->periodLockGuard?->refusal($user, $scopeKey, $instant, WriteKind::Import) instanceof PeriodWriteRefusedException;
    }

    /**
     * Removes one imported entry; returns the count (0 or 1).
     *
     * @throws PeriodLockedException When the entry is in a locked period (since 1.13)
     * @throws PeriodSubmittedException When the entry is in a submitted period (since 1.13)
     *
     * Since 1.15 an ownerless entry the guard protects is kept and the count is 0.
     */
    public function remove(string $externalSource, string $externalId, ?string $actorId = null): int
    {
        $this->checkSource($externalSource);
        if ($this->periodLockGuard?->isEnabled() === true) {
            $entry = $this->repository->findByExternal($externalSource, $externalId);
            $owner = $entry?->getUser();
            if ($entry instanceof TimeEntry && $owner instanceof UserInterface) {
                $this->periodLockGuard->assertWritable($owner, $entry->getScopeKey(), $entry->getStartedAt(), WriteKind::Delete);
            }
        }

        return $this->removeScope(ImportedTimeEntryScope::entry($externalSource, $externalId), $actorId);
    }

    /**
     * Removes every imported entry of the source owned by the user: re-association only (remove, then upsert). Since 1.15
     * never for a deleted or erased account: tracked time is not lost, so use
     * {@see FormerPersonTimeEntries} (`release()`, `anonymiseUser()`), or the `ON DELETE SET
     * NULL`. Since 1.13 this is the one removal the period lock does not stop.
     */
    public function removeForUser(string $externalSource, UserInterface $user): int
    {
        return $this->removeScope(ImportedTimeEntryScope::user($this->checkSource($externalSource), UserIdResolver::getId($user)), null, false);
    }

    /**
     * Removes the imported entries of the source tracked on these tasks: an explicit manager action on imported time
     * only. Never call it from a task deletion (deleting tasks keeps all tracked time). Since 1.13 entries of locked or
     * submitted periods are kept (not counted). Since 1.15 `$actorId` goes on {@see TimeEntriesRemovedEvent}, the record
     * of the removal.
     *
     * @param list<string> $taskIds
     */
    public function removeForTasks(string $externalSource, array $taskIds, ?string $actorId = null): int
    {
        return $this->removeScope(ImportedTimeEntryScope::tasks($this->checkSource($externalSource), $taskIds), $actorId);
    }

    /**
     * Removes the imported entries of the source whose board snapshot is this board: an explicit manager action on
     * imported time only. Never call it from a board deletion (deleting boards keeps all tracked time). Since 1.13
     * entries of locked or submitted periods are kept (not counted).
     */
    public function removeForBoard(string $externalSource, string $boardId, ?string $actorId = null): int
    {
        return $this->removeScope(ImportedTimeEntryScope::board($this->checkSource($externalSource), $boardId), $actorId);
    }

    /** Removes every imported entry of exactly this source (never a prefix match); since 1.13 locked periods are kept. */
    public function removeForSource(string $externalSource, ?string $actorId = null): int
    {
        return $this->removeScope(ImportedTimeEntryScope::source($this->checkSource($externalSource)), $actorId);
    }

    /**
     * The data the writer stores: patterns, timestamps (since 1.12: start year ≥ {@see self::MIN_YEAR}, end at most
     * {@see self::MAX_FUTURE_SECONDS} after now), span and duration checked, valid UTF-8 required, title cleaned to one line
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
        // Since 1.12 (FJ-I6): a plausibility window, so one absurd timestamp (epoch 0, year 0001 or 9999, far future)
        // is refused with a field and rule instead of failing the whole batch at the database.
        if ((int) $data->startedAt->format('Y') < self::MIN_YEAR) {
            throw new InvalidImportedTimeEntryException('startedAt', 'too_old');
        }
        if ($data->endedAt->getTimestamp() > ($this->clock?->now() ?? new DateTimeImmutable())->getTimestamp() + self::MAX_FUTURE_SECONDS) {
            throw new InvalidImportedTimeEntryException('endedAt', 'future');
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
        $title = ImportedDescriptionSanitizer::oneLine($data->taskTitle);

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

    private function removeScope(ImportedTimeEntryScope $scope, ?string $actorId, bool $respectLocks = true): int
    {
        $guard = $this->periodLockGuard;
        if ($respectLocks && $guard?->isEnabled() === true) {
            if (!$this->repository instanceof FilteredImportedTimeEntryRepositoryInterface) {
                throw new LogicException('With timesheets on, removing imported entries requires a repository implementing FilteredImportedTimeEntryRepositoryInterface.');
            }
            // @igor-ignore - I/O delegate (repository / writer call); no worker state.
            $removal = $this->repository->removeImportedWhere(
                $scope,
                static fn (array $row): bool => $guard->isRowBlocked($row['userId'], $row['personRef'], $row['scopeKey'], $row['startedAt']),
            );
        } else {
            // @igor-ignore - I/O delegate (repository / writer call); no worker state.
            $removal = $this->repository->removeImported($scope);
        }
        if ($removal->count > 0) {
            $this->eventDispatcher?->dispatch(new TimeEntriesRemovedEvent($scope->externalSource, $scope->kind, $removal, $actorId), TimeTrackEvents::TIME_ENTRIES_REMOVED);
            $this->logger->info('TimeTrack imported entries removed.', ['bundle' => 'nowo_time_track', 'scope' => $scope->kind, 'count' => $removal->count]);
        }

        return $removal->count;
    }

    /**
     * Whether the upsert keeps the entry with its owner. Since 1.15: an entry with a user only takes that user; an entry
     * of a deleted user takes a person (it keeps the identity it has) or that same user; an entry of a person takes that
     * person or a user (the claim), and one with no identity at all takes either.
     */
    private function sameOwner(TimeEntry $entry, UserInterface|ImportedTimeEntryPerson $owner): bool
    {
        $current = $entry->getUser();
        if ($current !== null) {
            return $current instanceof UserInterface && $owner instanceof UserInterface && UserIdResolver::getId($current) === UserIdResolver::getId($owner);
        }
        $formerUserId = $entry->getFormerUserId();
        if ($owner instanceof UserInterface) {
            return $formerUserId === null || $formerUserId === UserIdResolver::getId($owner);
        }

        return $formerUserId !== null || $entry->getPersonRef() === null || $entry->getPersonRef() === $owner->ref;
    }

    /**
     * Since 1.15: the person as stored: reference pattern, name on one line and cut to 255 (blank = none).
     *
     * @throws InvalidImportedTimeEntryException
     */
    private function validatePerson(ImportedTimeEntryPerson $person): ImportedTimeEntryPerson
    {
        if (preg_match(ImportedTimeEntryPerson::REF_PATTERN, $person->ref) !== 1 || str_starts_with($person->ref, TimeEntry::USER_REF_PREFIX)) {
            throw new InvalidImportedTimeEntryException('person.ref', 'pattern');
        }
        if ($person->name !== null && !mb_check_encoding($person->name, 'UTF-8')) {
            throw new InvalidImportedTimeEntryException('person.name', 'encoding');
        }
        if ($person->scopeKey !== null && strlen($person->scopeKey) > ImportedTimeEntryPerson::MAX_SCOPE_KEY_LENGTH) {
            throw new InvalidImportedTimeEntryException('person.scopeKey', 'length');
        }

        return new ImportedTimeEntryPerson($person->ref, ImportedTimeEntryPerson::cleanName($person->name), $person->scopeKey);
    }

    private function checkSource(string $externalSource): string
    {
        if (preg_match(self::SOURCE_PATTERN, $externalSource) !== 1) {
            throw new InvalidImportedTimeEntryException('externalSource', 'pattern');
        }

        return $externalSource;
    }
}
