<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Calendar\CalendarRuleService;
use Nowo\TimeTrackBundle\Calendar\CalendarSuggestionService;
use Nowo\TimeTrackBundle\Client\ClientTokenRevoker;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Entity\BalanceLedgerLine;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\CalendarRule;
use Nowo\TimeTrackBundle\Entity\CalendarSuggestion;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryDeleter;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryWriter;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimeEntryBillableService;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Timesheet\TimesheetService;
use Nowo\TimeTrackBundle\Transaction\TimeTrackTransactionRunner;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function is_a;
use function is_object;
use function sprintf;
use function strrpos;
use function substr;
use function trigger_deprecation;

/**
 * 1.33.0 (REQ-ARCH-001 rule 6 deprecation layer): the migration list of a host that runs the shared entity manager
 * (`database.private_entity_manager: false`, the 1.x default). Registered only in that mode: the private manager never
 * flushes the application's manager, so it needs no probe.
 *
 * Three kinds of `E_USER_DEPRECATED`, each at most once per class (and kind of write) per unit of work:
 *
 * - **Your changes flushed by a TimeTrack write.** Right before every TimeTrack flush of the shared manager
 *   ({@see RecoveringFlusher::flush()}, {@see TimeTrackTransactionRunner}) the shared nowo-tech/transaction-doctrine
 *   {@see HostFlushProbe} lists the application's entity classes with pending work: "Your pending changes to App\X were
 *   flushed by NowoTimeTrackBundle; 2.0 no longer does." Flush them yourself.
 * - **TimeTrack rows written by your manager.** A flush TimeTrack did not run that inserts, updates or deletes
 *   TimeTrack rows (Doctrine `onFlush` on `database.entity_manager`) names the TimeTrack class and, where one exists, the
 *   bundle operation or repository to use instead: in 2.0 the instances TimeTrack's services return belong to its
 *   private manager, so your flush no longer writes changes made to them.
 * - **Your rows that point at a TimeTrack instance.** The same flush inserts an application entity, or changes one's
 *   to-one association, that references a TimeTrack row: in 2.0 an instance TimeTrack returned is unknown to your manager
 *   (it would try to insert it), so reference it by id.
 *
 * A flush that deletes one of your users (`user_class`) is not inspected for TimeTrack row updates: the bundle's own
 * {@see FormerPersonSnapshotListener} settles that user's loaded entries inside it.
 *
 * Read-only: it never flushes (other than the TimeTrack flush it wraps), clears, closes, resets or detaches anything
 * (REQ-RUNTIME-003), and a failure of its own (a deprecation handler that throws) never fails a write. Its state (what
 * was already reported, whether a TimeTrack flush is running) is emptied at every request, message and command boundary
 * and at `kernel.reset`, so a FrankenPHP worker with kernel reset off reports each unit of work afresh. The shared probe
 * is reset by transaction-doctrine's own boundary listener when its bundle is registered; without it this service keeps
 * a probe of its own and resets it with itself.
 *
 * @internal
 */
final class TimeTrackHostFlushProbe implements ResetInterface
{
    /** The bundle name the deprecations show. */
    public const BUNDLE = 'NowoTimeTrackBundle';

    /** The package of the notices. */
    public const PACKAGE = 'nowo-tech/time-track-bundle';

    /** The minor that introduced the notices. */
    public const SINCE = '1.33';

    /** Write kinds, as the notices name them. */
    public const INSERT = 'inserted';

    public const UPDATE = 'updated';

    public const DELETE = 'deleted';

    /** What the notices suggest when no bundle operation exists for the write. */
    public const NO_OPERATION = 'no TimeTrack operation does this write yet: write it through TimeTrack\'s services and repositories with rows loaded through them, and pass ids, not your instances';

    /**
     * The bundle operation or repository to use instead of a write of the TimeTrack class through the application's
     * manager, where one exists.
     *
     * @var array<class-string, array<string, string>>
     */
    public const OPERATIONS = [
        TimeEntry::class => [
            self::INSERT => 'create entries with ' . ManualEntryService::class . '::create() (or ' . ImportedTimeEntryWriter::class . '::upsert() for rows of an external source)',
            self::UPDATE => 'change entries through TimeTrack: ' . TimeEntryBillableService::class . '::changeById() for the billable flag, ' . TimeEntryRepositoryInterface::class . '::save() for an entry loaded through TimeTrack',
            self::DELETE => 'delete imported entries with ' . ImportedTimeEntryDeleter::class . '::delete() or ' . ImportedTimeEntryWriter::class . '::remove(); release a person\'s entries with ' . FormerPersonTimeEntries::class . '::release()',
        ],
        ActiveTimer::class => [
            self::INSERT => 'start timers with ' . TimerService::class . '::start() (or startAt(), switchTo())',
            self::UPDATE => 'change timers with ' . TimerService::class . ' (switchTo(), keepAlive(), resolveIdle())',
            self::DELETE => 'stop timers with ' . TimerService::class . '::stop() (or stopAt())',
        ],
        ClientToken::class => [
            self::INSERT => 'sessions are issued by the client login and the device flow only',
            self::DELETE => 'end the sessions of a credential with ' . ClientTokenRevoker::class . '::revokeByCredential() (no TimeTrack operation ends one session by its id yet)',
        ],
        DeviceAuthorization::class => [
            self::UPDATE => 'decide device requests with ' . DeviceAuthorizationService::class . ' (approve(), deny())',
        ],
        Timesheet::class => [
            self::UPDATE => 'move timesheets with ' . TimesheetService::class . ' (submit(), withdraw(), approve(), reject(), reopen())',
        ],
        TimesheetEvent::class => [
            self::INSERT => 'timesheet events are written by ' . TimesheetService::class . ' only',
        ],
        BalanceLedgerLine::class => [
            self::INSERT => 'add ledger lines with ' . BalanceLedger::class . '::add()',
        ],
        CalendarConnection::class => [
            self::INSERT => 'connect calendars with ' . CalendarConnectionService::class . '::connect()',
            self::UPDATE => 'change calendar connections with ' . CalendarConnectionService::class . ' (setPaused(), revoke())',
            self::DELETE => 'remove calendar connections with ' . CalendarConnectionService::class . '::revoke() (or revokeAllFor())',
        ],
        CalendarRule::class => [
            self::INSERT => 'create calendar rules with ' . CalendarRuleService::class . '::create()',
            self::DELETE => 'delete calendar rules with ' . CalendarRuleService::class . '::delete()',
        ],
        CalendarSuggestion::class => [
            self::UPDATE => 'decide calendar suggestions with ' . CalendarSuggestionService::class . ' (accept(), dismiss())',
        ],
        HostUser::class => [
            self::DELETE => 'release a deleted user with ' . FormerPersonTimeEntries::class . '::release()',
        ],
    ];

    private readonly HostFlushProbe $probe;

    /** Whether {@see self::$probe} is this service's own (no transaction-doctrine bundle): then reset with it. */
    private readonly bool $ownProbe;

    /** TimeTrack flushes running right now (a flush inside a TimeTrack flush is TimeTrack's too). */
    private int $bundleFlushes = 0;

    /** @var array<string, true> notices already triggered in this unit of work */
    private array $reported = [];

    /**
     * @param HostFlushProbe|null $probe the shared probe (`nowo_transaction_doctrine.host_flush_probe`); null = a probe
     *                                   of this service's own, reset with it
     * @param string|null $userClass `user_class`: a flush that deletes one of these settles TimeTrack rows itself
     */
    public function __construct(?HostFlushProbe $probe = null, private readonly ?string $userClass = null)
    {
        $this->ownProbe = !$probe instanceof HostFlushProbe;
        $this->probe    = $probe ?? new HostFlushProbe();
    }

    /**
     * Runs a TimeTrack flush of the shared manager: reports the application's pending changes it writes first, and marks
     * the flush as TimeTrack's for {@see self::onFlush()}. Only the shared manager's flushes come here: the private
     * manager ({@see TimeTrackEntityManager}) gets no probe.
     *
     * @param EntityManagerInterface $entityManager the manager TimeTrack flushes
     * @param callable(): void $flush the flush of that manager
     */
    public function around(EntityManagerInterface $entityManager, callable $flush): void
    {
        try {
            $this->probe->beforeHostFlush($entityManager, self::BUNDLE, [TimeTrackManagerRegistry::ENTITY_NAMESPACE, TimeTrackManagerRegistry::USER_REF_NAMESPACE]);
        } catch (Throwable) {
            // A notice never stops a write (a deprecation handler that throws, an unexpected unit of work): the flush
            // below runs exactly as it did before 1.33.
        }
        // @igor-ignore - per unit of work, back to its value when the flush ends; emptied by reset() at every boundary
        ++$this->bundleFlushes;
        try {
            $flush();
        } finally {
            // @igor-ignore - per unit of work, see above
            --$this->bundleFlushes;
        }
    }

    /**
     * Doctrine `onFlush` of `database.entity_manager`: a flush TimeTrack did not run that writes TimeTrack rows, or
     * application rows pointing at TimeTrack instances.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->bundleFlushes > 0) {
            return;
        }
        try {
            $entityManager = $args->getObjectManager();
            $unitOfWork    = $entityManager->getUnitOfWork();
            $userDeleted   = $this->deletesAUser($unitOfWork);
            foreach ([
                self::INSERT => $unitOfWork->getScheduledEntityInsertions(),
                self::UPDATE => $unitOfWork->getScheduledEntityUpdates(),
                self::DELETE => $unitOfWork->getScheduledEntityDeletions(),
            ] as $kind => $entities) {
                foreach ($entities as $entity) {
                    $class = $this->realClass($entity::class);
                    if (TimeTrackManagerRegistry::isBundleEntity($class)) {
                        if ($kind !== self::UPDATE || !$userDeleted) {
                            $this->reportRow($class, $kind);
                        }
                    } elseif ($kind !== self::DELETE) {
                        $this->reportReferences($entityManager, $unitOfWork, $entity, $class, $kind);
                    }
                }
            }
        } catch (Throwable) {
            // Read-only inspection: whatever fails here never fails the application's flush.
        }
    }

    /** A new unit of work (request, message, command) starts: nothing reported yet. */
    public function reset(): void
    {
        // @igor-ignore - the boundary reset itself
        $this->reported = [];
        // @igor-ignore - the boundary reset itself
        $this->bundleFlushes = 0;
        if ($this->ownProbe) {
            $this->probe->reset();
        }
    }

    /**
     * The same as {@see self::reset()}, at the bundle's request, message and command boundaries (worker mode with kernel
     * reset off).
     */
    public function onBoundary(): void
    {
        $this->reset();
    }

    /**
     * One notice per TimeTrack class and kind of write per unit of work.
     */
    private function reportRow(string $class, string $kind): void
    {
        $instead = self::OPERATIONS[$class][$kind] ?? self::NO_OPERATION;
        $this->notice($class . '|' . $kind, sprintf(
            'Your application %s %s rows with its own entity manager, outside a TimeTrack write. In 2.0 TimeTrack reads and writes its rows only through its private entity manager, and the instances its services return are not in yours: %s (then set "nowo_time_track.database.private_entity_manager: true").',
            $kind,
            $class,
            $instead,
        ));
    }

    /**
     * An application entity inserted, or with a changed to-one association, that points at a TimeTrack row.
     */
    private function reportReferences(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork, object $entity, string $class, string $kind): void
    {
        $metadata = $entityManager->getClassMetadata($class);
        $changes  = $kind === self::UPDATE ? $unitOfWork->getEntityChangeSet($entity) : null;
        foreach ($metadata->getAssociationNames() as $field) {
            $target = $metadata->getAssociationTargetClass($field);
            if (!$metadata->isSingleValuedAssociation($field) || !TimeTrackManagerRegistry::isBundleEntity($target)) {
                continue;
            }
            if ($changes !== null && !isset($changes[$field])) {
                continue;
            }
            if (!is_object($metadata->getFieldValue($entity, $field))) {
                continue;
            }
            $this->notice($class . '|' . $field . '|' . $kind, sprintf(
                'Your application %s %s rows that point at a %s instance ("%s"). In 2.0 the %s instances TimeTrack\'s services return belong to its private entity manager, which yours does not know (it would try to insert them): reference the row by id with your manager instead ("getReference(%s::class, $id)"), then set "nowo_time_track.database.private_entity_manager: true".',
                $kind,
                $class,
                $target,
                $field,
                $target,
                $target,
            ));
        }
    }

    private function notice(string $key, string $message): void
    {
        if (isset($this->reported[$key])) {
            return;
        }
        // @igor-ignore - per unit of work, emptied by reset() at every boundary
        $this->reported[$key] = true;
        try {
            trigger_deprecation(self::PACKAGE, self::SINCE, '%s', $message);
        } catch (Throwable) {
            // A deprecation handler that throws never fails the application's flush (this runs inside its onFlush).
        }
    }

    /** Whether the flush deletes one of the application's users (the bundle settles their TimeTrack rows in it). */
    private function deletesAUser(UnitOfWork $unitOfWork): bool
    {
        if ($this->userClass === null || $this->userClass === '') {
            return false;
        }
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (is_a($entity, $this->userClass)) {
                return true;
            }
        }

        return false;
    }

    /** The entity class behind a Doctrine proxy class. */
    private function realClass(string $class): string
    {
        $marker = strrpos($class, '\\__CG__\\');

        return $marker === false ? $class : substr($class, $marker + 8);
    }
}
