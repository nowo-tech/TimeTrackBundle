<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Entry;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Access\ExactTimeEntryLookup;
use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\TimeEntryCorrectedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\TimeEntryCorrectionException;
use Nowo\TimeTrackBundle\Integration\ServiceAccountPolicyInterface;
use Nowo\TimeTrackBundle\Port\Internal\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Query\TimeEntryRecord;
use Nowo\TimeTrackBundle\Repository\ActiveTimerRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\LockingFindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Support\EntryOverlap;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function hash_equals;
use function in_array;
use function is_int;
use function is_string;
use function preg_match;

use const PHP_INT_MAX;

/**
 * Since 1.40.0: the bundle's {@see TimeEntryOperationsInterface} (public service, service id = the interface), on the
 * bundle's stores and entity manager (private or `database.entity_manager`). Stateless: every call reads the entry,
 * the owner and the host ports again (FrankenPHP worker mode, kernel reset false).
 */
final readonly class TimeEntryOperations implements TimeEntryOperationsInterface
{
    /** Integer identifier types of a `user_class` (an id that is not a positive integer in range names no user). */
    private const INTEGER_ID_TYPES = ['smallint' => 32_767, 'integer' => 2_147_483_647, 'bigint' => PHP_INT_MAX];

    /** UUID identifier types (an id that is not a canonical UUID names no user). */
    private const UUID_ID_TYPES = ['guid', 'uuid'];

    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager (private or `database.entity_manager`): owners by id
     * @param string $userClass `user_class`
     * @param UserReferenceResolverInterface|null $userReferences Creates the host-user row of a new owner (`user_mapping: id`)
     * @param int $maxAgeDays Since 1.40.1: `manual_entries.max_age_days`, the default oldest new start of a correction
     */
    public function __construct(
        private TimeEntryRepositoryInterface $entries,
        private ActiveTimerRepositoryInterface $timers,
        private ManualEntryService $manualEntries,
        private EntityManagerInterface $entityManager,
        private string $userClass,
        private ?UserReferenceResolverInterface $userReferences = null,
        private ?PeriodLockGuard $periodLockGuard = null,
        private ?ServiceAccountPolicyInterface $serviceAccounts = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        private int $maxAgeDays = 31,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@inheritDoc}
     */
    public function correct(TimeTrackUserRef|string|int $owner, string $entryId, string $expectedVersion, TimeEntryCorrection $correction): TimeEntryCorrectionResult
    {
        $ownerId = $this->ownerId($owner);
        $entry   = $ownerId === null ? null : ExactTimeEntryLookup::find($this->entries, $entryId);
        $user    = $entry?->getUser();
        if (!$entry instanceof TimeEntry || $ownerId === null || $entry->getUserId() !== $ownerId || $entry->isAnonymised() || !$user instanceof UserInterface) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::NOT_FOUND);
        }

        // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
        $result = $this->entries instanceof UserSerialisedTimeEntryRepositoryInterface
            ? $this->entries->inUserTransaction($user, fn (): TimeEntryCorrectionResult => $this->correctLocked($user, $ownerId, $entryId, $expectedVersion, $correction))
            : $this->correctLocked($user, $ownerId, $entryId, $expectedVersion, $correction);
        if (!$result->changed) {
            return $result;
        }

        try {
            $this->eventDispatcher?->dispatch(
                new TimeEntryCorrectedEvent($entryId, $ownerId, $result->before, $result->after, $result->changedFields, StorageTime::normalize($this->clock->now())),
                TimeTrackEvents::TIME_ENTRY_CORRECTED,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack entry-corrected listener failed.', ['bundle' => 'time_track', 'action' => 'entry_correct', 'exception' => $exception::class]);
        }
        $this->logger->info('TimeTrack entry corrected.', ['bundle' => 'time_track', 'action' => 'entry_correct', 'fields' => $result->changedFields]);

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function createManual(TimeTrackUserRef|string|int $owner, ManualTimeEntryDraft $draft): TimeEntryRecord
    {
        $user  = $this->ownerUser($owner) ?? throw new ManualEntryException(ManualEntryException::UNKNOWN_USER);
        $entry = $this->manualEntries->create(
            $user,
            $draft->taskId,
            $draft->startedAt,
            $draft->endedAt,
            TimeEntrySource::Manual,
            $draft->note,
            $draft->metadata,
            $draft->maxAgeDays,
            billable: $draft->billable,
            channel: $draft->channel,
            description: $draft->description,
            scopeKey: $draft->scopeKey,
        );

        return TimeEntryRecord::fromEntry($entry);
    }

    /**
     * The correction itself, inside the owner's unit: the entry read again (row lock with a locking store), every check
     * repeated on it, then the write.
     */
    private function correctLocked(UserInterface $user, string $ownerId, string $entryId, string $expectedVersion, TimeEntryCorrection $correction): TimeEntryCorrectionResult
    {
        $current = $this->entries instanceof LockingFindableTimeEntryRepositoryInterface ? $this->entries->findForUpdate($entryId) : ExactTimeEntryLookup::find($this->entries, $entryId);
        if (!$current instanceof TimeEntry || $current->getId() !== $entryId || $current->getUserId() !== $ownerId || $current->isAnonymised()) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::NOT_FOUND);
        }
        if ($current->isReadOnly()) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::READ_ONLY);
        }
        $before = TimeEntryRecord::fromEntry($current);
        if (!hash_equals($before->version(), $expectedVersion)) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::CONFLICT);
        }

        $oldStart     = $current->getStartedAt();
        $start        = $correction->startedAt instanceof DateTimeImmutable ? StorageTime::normalize($correction->startedAt) : $oldStart;
        $end          = $correction->endedAt instanceof DateTimeImmutable ? StorageTime::normalize($correction->endedAt) : $current->getEndedAt();
        $startChanged = $start->getTimestamp() !== $oldStart->getTimestamp();
        $timesChanged = $startChanged || $end->getTimestamp() !== $current->getEndedAt()->getTimestamp();
        $span         = $end->getTimestamp() - $start->getTimestamp();
        $this->assertTimes($end, $span, $timesChanged);
        // Audit ZAN-2: a new start no older than the allowed age (the correction's, else `manual_entries.max_age_days`).
        if ($startChanged && $start->getTimestamp() < $this->clock->now()->getTimestamp() - ($correction->maxAgeDays ?? $this->maxAgeDays) * 86_400) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::TOO_OLD);
        }
        $duration = $correction->durationSeconds ?? ($timesChanged ? $span : $current->getDurationSeconds());
        if ($duration > $span) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::INVALID_DURATION);
        }
        $oldMetadata = $current->getMetadata() ?? [];
        $metadata    = $this->patched($oldMetadata, $correction->metadata);
        if (EntryMetadataRules::grewBeyondLimits($oldMetadata, $metadata)) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::METADATA_TOO_LARGE);
        }

        $fields = [];
        foreach (['started_at' => $startChanged, 'ended_at' => $end->getTimestamp() !== $current->getEndedAt()->getTimestamp(), 'duration_seconds' => $duration !== $current->getDurationSeconds(), 'metadata' => $metadata !== $oldMetadata] as $field => $changed) {
            if ($changed) {
                $fields[] = $field;
            }
        }
        if ($fields === []) {
            return new TimeEntryCorrectionResult($before, $before, []);
        }

        // The period of the old start and of the new one: a locked, approved or submitted period never changes. Read under
        // a shared lock (audit ZAN-1), like the overlap check below.
        $this->periodLockGuard?->assertWritable($user, $current->getScopeKey(), $oldStart, WriteKind::Edit, true);
        if ($startChanged) {
            $this->periodLockGuard?->assertWritable($user, $current->getScopeKey(), $start, WriteKind::Edit, true);
        }
        // Asked per call, never memoised: a service account's entries may overlap (as for manual entries).
        if ($timesChanged && !($this->serviceAccounts?->isServiceAccount(TimeTrackUserRefs::fromUser($user)) ?? false) && EntryOverlap::exists($this->entries, $this->timers, $ownerId, $start, $end, $entryId)) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::OVERLAP);
        }
        $current->applyCorrection($start, $end, $duration, $metadata === [] ? null : $metadata);
        $this->entries->save($current);

        return new TimeEntryCorrectionResult($before, TimeEntryRecord::fromEntry($current), $fields);
    }

    /**
     * @throws TimeEntryCorrectionException
     */
    private function assertTimes(DateTimeImmutable $end, int $span, bool $timesChanged): void
    {
        if ($span <= 0) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::INVALID_RANGE);
        }
        // Audit ZAN-I3: only a change is checked against the 24 h cap, so a forgotten timer's long entry can still get a
        // note or duration fix.
        if ($timesChanged && $span > ManualEntryService::MAX_SECONDS) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::TOO_LONG);
        }
        // Only a change is checked against the clock: an entry stored within the accepted skew keeps its end.
        if ($timesChanged && $end->getTimestamp() > $this->clock->now()->getTimestamp() + ManualEntryService::CLOCK_SKEW_SECONDS) {
            throw new TimeEntryCorrectionException(TimeEntryCorrectionException::IN_FUTURE);
        }
    }

    /**
     * The stored metadata with the patch applied (a null value removes the key; the note is cleaned and cut, and an empty
     * note removes it).
     *
     * @param array<string, mixed> $metadata
     * @param array<string, scalar|null> $patch
     *
     * @return array<string, mixed>
     */
    private function patched(array $metadata, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($key === 'note' && is_string($value)) {
                $value = NoteSanitizer::cleanAndCut($value);
            }
            if ($value === null) {
                unset($metadata[$key]);
            } else {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * The owner's id: a usable id compared byte for byte, or null (empty, padded, invisible characters, longer than 128,
     * or a reference whose user object has another id).
     */
    private function ownerId(TimeTrackUserRef|string|int $owner): ?string
    {
        if ($owner instanceof TimeTrackUserRef) {
            $object = $owner->user();
            $id     = $object !== null && UserIdResolver::idOf($object) !== $owner->id ? null : $owner->id;
        } else {
            $id = is_int($owner) ? (string) $owner : $owner;
        }

        return $id !== null && $id !== '' && DoctrineUserReferenceResolver::isExactId($id) ? $id : null;
    }

    /**
     * The owner as the bundle stores it: the security user a reference carries, else the `user_class` row with exactly
     * this id (relation mapping) or the bundle's host-user row, created when needed (id mapping). Null for an unknown id.
     */
    private function ownerUser(TimeTrackUserRef|string|int $owner): ?UserInterface
    {
        $id = $this->ownerId($owner);
        if ($id === null) {
            return null;
        }
        $object = $owner instanceof TimeTrackUserRef ? $owner->user() : null;
        if ($object instanceof UserInterface) {
            return $object;
        }
        $class = $this->entityManager->getClassMetadata(TimeEntry::class)->getAssociationTargetClass('user');
        if (!$this->isIdOfType($class, $id)) {
            return null;
        }
        $found = $this->entityManager->find($class, $id);
        if ($found === null && $class === HostUser::class) {
            $found = $this->userReferences?->reference(new HostUser($id, $this->userClass));
        }

        return $found instanceof UserInterface && UserIdResolver::idOf($found) === $id ? $found : null;
    }

    /**
     * Whether the id can name a row of the class (an integer or UUID identifier column refuses other values in SQL, and
     * PostgreSQL would fail the whole transaction on them).
     *
     * @param class-string $class
     */
    private function isIdOfType(string $class, string $id): bool
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $type     = $metadata->getTypeOfField($metadata->getSingleIdentifierFieldName());
        if (is_string($type) && isset(self::INTEGER_ID_TYPES[$type])) {
            return preg_match('/^[1-9][0-9]{0,18}$/', $id) === 1 && (string) (int) $id === $id && (int) $id <= self::INTEGER_ID_TYPES[$type];
        }

        return !in_array($type, self::UUID_ID_TYPES, true) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1;
    }
}
