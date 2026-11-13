<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Import;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\TimeEntriesMovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntriesRemovedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodSubmittedException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\FilteredImportedTimeEntryStoreInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryOwnerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\ImportedTimeEntryStoreInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\Timesheet\ScopeKeys;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_chunk;
use function array_map;
use function count;
use function in_array;
use function is_string;
use function mb_check_encoding;
use function mb_substr;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strtolower;

/**
 * Since 1.11: writes imported time entries ({@see TimeEntrySource::Import}), idempotently by
 * (externalSource, externalId). It never starts or stops a timer and never fires timer events; it never moves an entry
 * to another owner by an upsert (since 1.16 use {@see self::moveToPerson()} and its siblings, which delete nothing). The host authorises every call (who may import or delete).
 *
 * Since 1.15 a row may name a person with no user account instead of a user ({@see ImportedTimeEntryPerson}): the entry
 * has no owner and shows under the person's name. A user takes it only through an explicit
 * {@see ImportedTimeEntryClaim}. An entry of a deleted user, one with no identity and an anonymised one are frozen for a
 * sync (`Unchanged`), so nobody rewrites those hours and a re-sync cannot bring a name back.
 *
 * Since 1.13 (timesheets on): every written entry gets the tenant scope of its task, and a row whose entry is (or
 * would move) into a locked or submitted period is skipped with {@see ImportedTimeEntryOutcome::Locked} (contract
 * Q-O13); removals never delete an entry of such a period ({@see self::remove()} refuses, the bulk removals skip it).
 *
 * Owner rule (2026-11-05, widened 2026-11-06): deleting tasks, boards or users never deletes tracked time. `remove*()` are
 * for an explicit manager action on imported time, ownerless entries included (the owner's ruling: "never deleted" means
 * never as a side effect), and `removeForUser()` is for re-association only. Each respects the period lock as stated
 * on it and is recorded by {@see TimeEntriesRemovedEvent} (count, seconds, owners, people, actor) and a log line of counts;
 * the bundle keeps no audit table of its own, so the host stores the event.
 */
final readonly class ImportedTimeEntryWriter
{
    private UserReferenceResolverInterface $userReferences;

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

    private const TRANSITION_KEEP     = 'keep';
    private const TRANSITION_CLAIM    = 'claim';
    private const TRANSITION_FROZEN   = 'frozen';
    private const TRANSITION_MISMATCH = 'mismatch';

    /** Since 1.16: rows one read of a move handles. */
    private const MOVE_BATCH = 500;

    private ImportedTimeEntryStoreInterface $repository;

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
        ?UserReferenceResolverInterface $userReferences = null,
    ) {
        if (!$repository instanceof ImportedTimeEntryStoreInterface) {
            throw new LogicException('Imported time entries require a repository implementing ImportedTimeEntryStoreInterface (or the deprecated ImportedTimeEntryRepositoryInterface).');
        }
        $this->repository     = $repository;
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
    }

    /**
     * Creates or updates the entry of the external identity; an unchanged entry is not written and fires nothing.
     *
     * @throws InvalidImportedTimeEntryException when the data breaks a rule (nothing written)
     * @throws ImportedEntryOwnerMismatchException when the entry exists for another user (nothing written)
     */
    public function upsert(ImportedTimeEntryClaim|UserInterface $user, ImportedTimeEntryData $data): ImportedTimeEntryResult
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
     * Since 1.15 a row may name a person instead of a user ({@see self::upsertForPerson()}), or claim for a user
     * ({@see ImportedTimeEntryClaim}).
     *
     * Since 1.16 `$skipMismatches` makes a row naming another owner a per-row outcome ({@see ImportedTimeEntryOutcome::Mismatch},
     * nothing written for it) instead of refusing the whole batch with {@see ImportedEntryOwnerMismatchException}.
     *
     * @param list<array{0: ImportedTimeEntryClaim|ImportedTimeEntryPerson|UserInterface, 1: ImportedTimeEntryData}> $rows
     */
    public function upsertMany(array $rows, bool $skipMismatches = false): ImportedTimeEntryBatchResult
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
            return $this->write($valid, $skipMismatches);
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
            // Since 1.23.0 a store implementing only ImportedTimeEntryStoreInterface keeps the owners as they are.
            $store = $this->repository instanceof ImportedTimeEntryRepositoryInterface ? $this->repository : null;
            foreach ($valid as [$owner, $data]) {
                $retry[] = [match (true) {
                    !$store instanceof ImportedTimeEntryRepositoryInterface => $owner,
                    $owner instanceof UserInterface                         => $store->attachUser($owner),
                    $owner instanceof ImportedTimeEntryClaim                => new ImportedTimeEntryClaim($store->attachUser($owner->user), $owner->personRef, $owner->scopeKey),
                    default                                                 => $owner,
                }, $data];
            }

            return $this->write($retry, $skipMismatches);
        }
    }

    /**
     * @param list<array{0: ImportedTimeEntryClaim|ImportedTimeEntryPerson|UserInterface, 1: ImportedTimeEntryData}> $rows validated rows
     */
    private function write(array $rows, bool $skipMismatches): ImportedTimeEntryBatchResult
    {
        $plan = [];
        foreach ($rows as [$owner, $data]) {
            $user     = $this->userOf($owner);
            $scopeKey = $user instanceof UserInterface ? ($this->scopeProvider?->scopeKeyForTask($user, $data->taskId, $data->boardId) ?? ($owner instanceof ImportedTimeEntryClaim ? $owner->scopeKey : null)) : null;
            if ($scopeKey !== null && !ScopeKeys::isUsable($scopeKey)) {
                // Since 1.24.1 (REQ-SEC-006): a scope key a look-alike could stand for is refused before anything is written.
                throw new InvalidImportedTimeEntryException('scopeKey', 'pattern');
            }
            $existing = $this->repository->findByExternal($data->externalSource, $data->externalId)
                ?? $this->repository->findByExternal($data->externalSource, TimeEntry::anonymisedExternalId($data->externalSource, $data->externalId));
            try {
                $transition = $existing instanceof TimeEntry ? $this->transition($existing, $owner, $user, $scopeKey, $data->externalId) : null;
            } catch (ImportedEntryOwnerMismatchException $exception) {
                if (!$skipMismatches) {
                    throw $exception;
                }
                $transition = self::TRANSITION_MISMATCH;
            }
            $plan[] = [$owner, $user, $scopeKey, $data, $existing, $transition];
        }

        $results = [];
        $write   = [];
        $events  = [];
        $counts  = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'locked' => 0, 'mismatched' => 0];
        foreach ($plan as [$owner, $user, $scopeKey, $data, $existing, $transition]) {
            if (!$existing instanceof TimeEntry) {
                $entry = $owner instanceof ImportedTimeEntryPerson ? TimeEntry::importedForPerson($owner, $data) : TimeEntry::imported($user instanceof UserInterface ? $this->userReferences->reference($user) : $user, $data)->assignScopeKey($scopeKey);
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
            if ($transition === self::TRANSITION_MISMATCH) {
                $results[] = new ImportedTimeEntryResult($existing, ImportedTimeEntryOutcome::Mismatch);
                ++$counts['mismatched'];
                continue;
            }
            $previousSeconds = $existing->getDurationSeconds();
            $previousTask    = $existing->getTaskId();
            $claimed         = $transition === self::TRANSITION_CLAIM;
            $renamed         = $owner instanceof ImportedTimeEntryPerson && $transition === self::TRANSITION_KEEP && $existing->getPersonName() !== $owner->name;
            if ($transition === self::TRANSITION_FROZEN || (!$existing->differsFromImport($data) && !$claimed && !$renamed)) {
                $results[] = new ImportedTimeEntryResult($existing, ImportedTimeEntryOutcome::Unchanged);
                ++$counts['unchanged'];
                continue;
            }
            // The stored entry may not leave a locked period, and the new data may not enter one.
            if ($this->isEntryLocked($existing, $data) || ($user instanceof UserInterface && $this->isLocked($user, $scopeKey, $data->startedAt))) {
                $results[] = new ImportedTimeEntryResult($existing, ImportedTimeEntryOutcome::Locked);
                ++$counts['locked'];
                continue;
            }
            $existing->applyImport($data);
            if ($owner instanceof ImportedTimeEntryPerson) {
                $existing->applyPersonName($owner->name);
            } elseif ($claimed && $user instanceof UserInterface) {
                $existing->claimFor($this->userReferences->reference($user));
            }
            if ($scopeKey !== null || ($user instanceof UserInterface && $this->scopeProvider instanceof TimesheetScopeProviderInterface)) {
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
            $this->logger->info('TimeTrack imported entries written.', ['bundle' => 'nowo_time_track', 'created' => $counts['created'], 'updated' => $counts['updated'], 'unchanged' => $counts['unchanged'], 'locked' => $counts['locked'], 'mismatched' => $counts['mismatched']]);
        }
        if ($counts['mismatched'] > 0) {
            // Audit HM-L5: a skipped row is a refusal; counts only, never ids.
            $this->logger->warning('TimeTrack imported entries skipped: another owner holds them.', ['bundle' => 'nowo_time_track', 'mismatched' => $counts['mismatched']]);
        }

        return new ImportedTimeEntryBatchResult($results, $counts['created'], $counts['updated'], $counts['unchanged'], $counts['locked'], $counts['mismatched']);
    }

    /**
     * Since 1.15: whether the stored entry's period, or the period it would move to, refuses the write: through the refusal
     * path while it has a user, by the guard's ownerless rule otherwise.
     */
    private function isEntryLocked(TimeEntry $entry, ImportedTimeEntryData $data): bool
    {
        $owner = $entry->getUser();
        if ($owner instanceof UserInterface) {
            return $this->isLocked($owner, $entry->getScopeKey(), $entry->getStartedAt());
        }
        if (!$this->periodLockGuard instanceof PeriodLockGuard || !$this->periodLockGuard->isEnabled()) {
            return false;
        }

        return !$this->periodLockGuard->isEntryWritable($entry)
            || $this->periodLockGuard->isRowBlocked(null, $entry->getPersonRef(), $entry->getScopeKey(), $data->startedAt);
    }

    private function userOf(ImportedTimeEntryClaim|ImportedTimeEntryPerson|UserInterface $owner): ?UserInterface
    {
        return match (true) {
            $owner instanceof UserInterface          => $owner,
            $owner instanceof ImportedTimeEntryClaim => $owner->user,
            default                                  => null,
        };
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
     * Removes every imported entry of the source owned by the user: an explicit manager action. To re-associate time, since 1.16
     * use {@see self::moveToPerson()} and its siblings, which delete nothing. Never for
     * a deleted or erased account: tracked time is not lost, so use
     * {@see FormerPersonTimeEntries} (`release()`, `anonymiseUser()`), or the `ON DELETE SET
     * NULL`. Since 1.15 it respects the period lock like the other removals (1.13 and 1.14 skipped it for erasure).
     */
    public function removeForUser(string $externalSource, UserInterface $user, ?string $actorId = null): int
    {
        return $this->removeScope(ImportedTimeEntryScope::user($this->checkSource($externalSource), UserIdResolver::getId($user)), $actorId);
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
     * Since 1.16: moves the source's imported entries from a user to a person, who holds them with no owner. Never deletes
     * time: seconds, times, task and external id stay. One transaction, nested into the caller's when there is one; bulk
     * statements, no flush, and loaded rows of the move are detached. The host authorises the call.
     *
     * A row in a locked or submitted period of the user stays ({@see ImportedTimeEntryMoveResult::$keptLocked}). A row whose scope
     * is not exactly the person's stays ({@see ImportedTimeEntryMoveResult::$refusedScope}): the scope is never rewritten. A row
     * that stays will mismatch a later person row: use `upsertMany($rows, skipMismatches: true)`.
     *
     * @param list<string>|null $externalIds only these entries, when given
     *
     * @throws InvalidImportedTimeEntryException when the source or the person break a rule (nothing written)
     */
    public function moveToPerson(string $externalSource, UserInterface $from, ImportedTimeEntryPerson $to, ?array $externalIds = null, ?string $actorId = null): ImportedTimeEntryMoveResult
    {
        return $this->move($externalSource, $from, null, null, $this->validatePerson($to), $to->scopeKey, $externalIds, $actorId);
    }

    /**
     * Since 1.16: moves the source's imported entries of the claim's person to its user, the same way as {@see self::moveToPerson()}.
     * The claim's rules apply per row: a row whose scope is not exactly the target's (the scope provider's for that task, else
     * the claim's), or with a provider is not among `scopesFor()` of the new owner, is refused and stays, and a deleted user's reference only goes back to that same id. A row in a locked or
     * submitted period, of the deleted user or of the new owner, stays.
     *
     * @param list<string>|null $externalIds only these entries, when given
     *
     * @throws InvalidImportedTimeEntryException when the source breaks a rule (nothing written)
     */
    public function moveToUser(string $externalSource, ImportedTimeEntryClaim $to, ?array $externalIds = null, ?string $actorId = null): ImportedTimeEntryMoveResult
    {
        return $this->move($externalSource, null, $to->personRef, $to->user, null, $to->scopeKey, $externalIds, $actorId);
    }

    /**
     * Since 1.16: moves the source's imported entries from one user to another, the same way as {@see self::moveToUser()}.
     *
     * @param string|null $scopeKey the new owner's scope, used when `timesheets.scope_provider` gives none
     * @param list<string>|null $externalIds only these entries, when given
     *
     * @throws InvalidImportedTimeEntryException when the source breaks a rule (nothing written)
     */
    public function moveBetweenUsers(string $externalSource, UserInterface $from, UserInterface $to, ?string $scopeKey = null, ?array $externalIds = null, ?string $actorId = null): ImportedTimeEntryMoveResult
    {
        return $this->move($externalSource, $from, null, $to, null, $scopeKey, $externalIds, $actorId);
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

    /**
     * @param list<string>|null $externalIds
     */
    private function move(string $externalSource, ?UserInterface $fromUser, ?string $fromRef, ?UserInterface $toUser, ?ImportedTimeEntryPerson $toPerson, ?string $toScope, ?array $externalIds, ?string $actorId): ImportedTimeEntryMoveResult
    {
        $this->checkSource($externalSource);
        foreach ($externalIds ?? [] as $externalId) {
            if (!is_string($externalId) || preg_match(self::ID_PATTERN, $externalId) !== 1) {
                throw new InvalidImportedTimeEntryException('externalIds', 'pattern');
            }
        }
        if ($toScope !== null && !ScopeKeys::isUsable($toScope)) {
            // Since 1.24.1 (REQ-SEC-006).
            throw new InvalidImportedTimeEntryException('scopeKey', 'pattern');
        }
        if (!$this->repository instanceof ImportedTimeEntryOwnerRepositoryInterface) {
            throw new LogicException('Moving imported entries requires a repository implementing ImportedTimeEntryOwnerRepositoryInterface.');
        }
        $store      = $this->repository;
        $fromUserId = $fromUser instanceof UserInterface ? UserIdResolver::getId($fromUser) : null;
        $toUserId   = $toUser instanceof UserInterface ? UserIdResolver::getId($toUser) : null;
        if ($fromUserId !== null && $fromUserId === $toUserId) {
            return new ImportedTimeEntryMoveResult();
        }
        $formerUser = TimeEntry::formerUserIdOf($fromRef);
        $refuseAll  = $formerUser !== null && $formerUser !== $toUserId;
        $chunks     = $externalIds === null ? [null] : array_chunk($externalIds, self::MOVE_BATCH);
        $tenants    = $toUser instanceof UserInterface ? $this->tenantsOf($toUser) : null;

        // @igor-ignore - I/O delegate (repository / writer call); no worker state.
        $result = $store->inOwnerMoveTransaction(function () use ($store, $externalSource, $fromUserId, $fromRef, $toUser, $toUserId, $toPerson, $toScope, $chunks, $refuseAll, $tenants): ImportedTimeEntryMoveResult {
            $total = new ImportedTimeEntryMoveResult();
            foreach ($chunks as $chunk) {
                $after = '';
                // Until a read finds nothing: a locking read that waited can return fewer rows than its limit.
                do {
                    $rows    = $store->findForOwnerMove($externalSource, $fromUserId, $fromRef, $chunk, $after, self::MOVE_BATCH);
                    $move    = [];
                    $seconds = 0;
                    $kept    = 0;
                    $refused = 0;
                    foreach ($rows as $row) {
                        $after = $row['id'];
                        // A MySQL collation may fold case, and a cast may match more: everything named is compared here too.
                        if ($row['externalSource'] !== $externalSource || ($chunk !== null && !in_array($row['externalId'], $chunk, true)) || ($fromRef !== null && $row['personRef'] !== $fromRef)) {
                            // @codeCoverageIgnoreStart
                            continue;
                            // @codeCoverageIgnoreEnd
                        }
                        $scope = $toPerson instanceof ImportedTimeEntryPerson ? $toScope : ($toUser instanceof UserInterface ? $this->scopeProvider?->scopeKeyForTask($toUser, $row['taskId'], $row['boardId']) : null) ?? $toScope;
                        if ($refuseAll || $row['scopeKey'] !== $scope || ($tenants !== null && !in_array($row['scopeKey'], $tenants, true))) {
                            ++$refused;
                            continue;
                        }
                        if ($this->isMoveLocked($fromUserId, $fromRef, $toUserId, $row['scopeKey'], $row['startedAt'])) {
                            ++$kept;
                            continue;
                        }
                        $move[] = $row['id'];
                        $seconds += (int) $row['seconds'];
                    }
                    $moved = $move === [] ? 0 : $store->applyOwnerMove($move, $fromUserId, $fromRef, $toUser, $toPerson?->ref, $toPerson?->name);
                    $total = $total->merge(new ImportedTimeEntryMoveResult($moved, $kept, $refused, $seconds));
                } while ($rows !== []);
            }

            return $total;
        });

        if ($result->moved > 0 || $result->keptLocked > 0 || $result->refusedScope > 0) {
            $this->eventDispatcher?->dispatch(new TimeEntriesMovedEvent($externalSource, $fromUserId, $fromRef, $toUserId, $toPerson?->ref, $result, $actorId), TimeTrackEvents::TIME_ENTRIES_MOVED);
            $this->logger->info('TimeTrack imported entries moved.', ['bundle' => 'nowo_time_track', 'moved' => $result->moved, 'kept' => $result->keptLocked, 'refused' => $result->refusedScope, 'has_actor' => $actorId !== null]);
        }

        return $result;
    }

    /**
     * Since 1.16 (audit HM-M1): the tenants a user belongs to, by the scope provider; null without one, when the host vouches
     * for the scope it passes. A provider's task answer says where the task is, not whether this user is there.
     *
     * @return list<string>|null
     */
    private function tenantsOf(UserInterface $user): ?array
    {
        return $this->scopeProvider instanceof TimesheetScopeProviderInterface
            ? array_map(static fn (TimesheetScope $scope): string => $scope->key, $this->scopeProvider->scopesFor($user))
            : null;
    }

    /** Since 1.16: whether the period lock keeps a row where it is, for its current owner or for the new user. */
    private function isMoveLocked(?string $fromUserId, ?string $fromRef, ?string $toUserId, ?string $scopeKey, DateTimeImmutable $startedAt): bool
    {
        $guard = $this->periodLockGuard;
        if (!$guard instanceof PeriodLockGuard || !$guard->isEnabled()) {
            return false;
        }

        return $guard->isMoveBlocked($fromUserId, $fromRef, $scopeKey, $startedAt)
            || ($toUserId !== null && $guard->isMoveBlocked($toUserId, null, $scopeKey, $startedAt));
    }

    private function removeScope(ImportedTimeEntryScope $scope, ?string $actorId): int
    {
        $guard = $this->periodLockGuard;
        if ($guard?->isEnabled() === true) {
            if (!$this->repository instanceof FilteredImportedTimeEntryStoreInterface) {
                throw new LogicException('With timesheets on, removing imported entries requires a repository implementing FilteredImportedTimeEntryStoreInterface (or the deprecated FilteredImportedTimeEntryRepositoryInterface).');
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
        if ($removal->count > 0 || $removal->kept > 0) {
            $this->eventDispatcher?->dispatch(new TimeEntriesRemovedEvent($scope->externalSource, $scope->kind, $removal, $actorId), TimeTrackEvents::TIME_ENTRIES_REMOVED);
            $this->logger->info('TimeTrack imported entries removed.', ['bundle' => 'nowo_time_track', 'scope' => $scope->kind, 'count' => $removal->count, 'kept' => $removal->kept, 'has_actor' => $actorId !== null]);
        }

        return $removal->count;
    }

    /**
     * What an upsert may do to a stored entry, compared case-sensitively in PHP (a MySQL collation may not be). An entry
     * with a user only takes that user. An ownerless entry takes its own person, or a claim naming its reference in exactly its
     * scope (no scope on the row means no scope on the claim, so a claim cannot cross tenants). An entry of a deleted user, or with no identity, or anonymised, is frozen for a sync: `Unchanged`, so one row
     * does not stop a batch and nobody can rewrite those hours.
     *
     * @throws ImportedEntryOwnerMismatchException
     */
    private function transition(TimeEntry $entry, ImportedTimeEntryClaim|ImportedTimeEntryPerson|UserInterface $owner, ?UserInterface $user, ?string $scopeKey, string $externalId): string
    {
        if ($entry->isAnonymised()) {
            return self::TRANSITION_FROZEN;
        }
        $current = $entry->getUser();
        if ($current !== null) {
            if ($current instanceof UserInterface && $user instanceof UserInterface && UserIdResolver::getId($current) === UserIdResolver::getId($user)) {
                return self::TRANSITION_KEEP;
            }

            throw new ImportedEntryOwnerMismatchException($externalId);
        }
        $personRef = $entry->getPersonRef();
        if ($personRef === null) {
            return self::TRANSITION_FROZEN;
        }
        $storedScope = $entry->getScopeKey();
        $formerUser  = $entry->getFormerUserId();
        if ($owner instanceof ImportedTimeEntryPerson) {
            if ($formerUser !== null) {
                return self::TRANSITION_FROZEN;
            }
            if ($personRef !== $owner->ref || ($storedScope !== null && $storedScope !== $owner->scopeKey)) {
                throw new ImportedEntryOwnerMismatchException($externalId);
            }

            return self::TRANSITION_KEEP;
        }
        if (!$owner instanceof ImportedTimeEntryClaim || $owner->personRef !== $personRef
            || ($formerUser !== null && $formerUser !== UserIdResolver::getId($owner->user))
            || $storedScope !== $scopeKey) {
            throw new ImportedEntryOwnerMismatchException($externalId);
        }

        // Audit HM-M1: the provider's task answer says where the task is, not whether this user is there.
        $tenants = $this->tenantsOf($owner->user);
        if ($tenants !== null && !in_array($storedScope, $tenants, true)) {
            throw new ImportedEntryOwnerMismatchException($externalId);
        }

        return self::TRANSITION_CLAIM;
    }

    /**
     * Since 1.15: the person as stored: reference pattern, name on one line and cut to 255 (blank = none).
     *
     * @throws InvalidImportedTimeEntryException
     */
    private function validatePerson(ImportedTimeEntryPerson $person): ImportedTimeEntryPerson
    {
        if (preg_match(ImportedTimeEntryPerson::REF_PATTERN, $person->ref) !== 1 || str_starts_with(strtolower($person->ref), TimeEntry::USER_REF_PREFIX)) {
            throw new InvalidImportedTimeEntryException('person.ref', 'pattern');
        }
        if ($person->name !== null && !mb_check_encoding($person->name, 'UTF-8')) {
            throw new InvalidImportedTimeEntryException('person.name', 'encoding');
        }
        if ($person->scopeKey !== null && strlen($person->scopeKey) > ImportedTimeEntryPerson::MAX_SCOPE_KEY_LENGTH) {
            throw new InvalidImportedTimeEntryException('person.scopeKey', 'length');
        }
        if ($person->scopeKey !== null && !ScopeKeys::isUsable($person->scopeKey)) {
            // Since 1.24.1 (REQ-SEC-006): no edge space, other blank, control, format or invisible character.
            throw new InvalidImportedTimeEntryException('person.scopeKey', 'pattern');
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
