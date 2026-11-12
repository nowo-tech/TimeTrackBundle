<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\WriteKind;
use Nowo\TimeTrackBundle\Event\TimeEntryBillableChangedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Exception\BillableChangeRefusedException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Repository\FindableTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\UserSerialisedTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Support\StorageTime;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\PeriodLockGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Since 1.21 (Beacon D-20261111-12): changes the `billable` flag of a live entry — the authorised, audited setter hosts
 * and the client API (`PATCH entries/{id}`) use instead of writing the column.
 *
 * Refused ({@see BillableChangeRefusedException}) for an imported entry (`read_only`), an anonymised or ownerless entry
 * (`anonymised`) and when the edit grant says no (`forbidden`: {@see TeamAccessGuard::isEditGranted()} — the owner, a
 * manager with `security.manager_can_edit_entries`, an admin role, then the `edit` access-check event). Then the period
 * lock ({@see PeriodLockGuard}, {@see WriteKind::Edit}) refuses an entry of a locked or submitted (approval pending) period
 * with {@see PeriodWriteRefusedException} (409); no role or listener overrides it. The lock check and the write run in one
 * transaction holding the owner's write lock. Each real change dispatches {@see TimeEntryBillableChangedEvent} after the
 * commit (who, when, old and new value); a listener that throws is logged and does not undo the change. Logs carry the
 * action only (REQ-OBS-001).
 */
final readonly class TimeEntryBillableService
{
    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * Receives the store, the access guard, the period lock and the dispatcher of the audit event.
     */
    public function __construct(
        private TimeEntryRepositoryInterface $entries,
        private TeamAccessGuard $accessGuard,
        private ?PeriodLockGuard $periodLockGuard = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * {@see self::change()} for the entry with this id.
     *
     * @param string $via `api` or `host` (recorded on the audit event)
     *
     * @throws BillableChangeRefusedException `not_found` when there is no such entry (or the store cannot look it up)
     * @throws PeriodWriteRefusedException When the period is locked or submitted
     */
    public function changeById(UserInterface $actor, string $entryId, ?bool $billable, string $via = 'host'): TimeEntry
    {
        $entry = $this->find($entryId);
        if (!$entry instanceof TimeEntry) {
            throw new BillableChangeRefusedException(BillableChangeRefusedException::NOT_FOUND);
        }

        return $this->change($actor, $entry, $billable, $via);
    }

    /**
     * The entry with this id (null when there is none, or the store cannot look it up). No access check.
     */
    public function find(string $entryId): ?TimeEntry
    {
        return $this->entries instanceof FindableTimeEntryRepositoryInterface ? $this->entries->findById($entryId) : null;
    }

    /**
     * Sets the entry's `billable` flag (null = unset) as the actor; returns the entry. Setting the value it already has
     * writes and records nothing (the checks still run).
     *
     * @param string $via `api` or `host` (recorded on the audit event)
     *
     * @throws BillableChangeRefusedException `read_only`, `anonymised` or `forbidden`
     * @throws PeriodWriteRefusedException When the period is locked or submitted
     */
    public function change(UserInterface $actor, TimeEntry $entry, ?bool $billable, string $via = 'host'): TimeEntry
    {
        if ($entry->isReadOnly()) {
            throw new BillableChangeRefusedException(BillableChangeRefusedException::READ_ONLY);
        }
        $owner = $entry->getUser();
        if ($entry->isAnonymised() || !$owner instanceof UserInterface) {
            throw new BillableChangeRefusedException(BillableChangeRefusedException::ANONYMISED);
        }
        if (!$this->accessGuard->isEditGranted($actor, $entry)) {
            $this->logger->info('TimeTrack billable change refused.', ['bundle' => 'time_track', 'action' => 'entry_billable', 'reason' => BillableChangeRefusedException::FORBIDDEN]);

            throw new BillableChangeRefusedException(BillableChangeRefusedException::FORBIDDEN);
        }

        $old = $entry->getBillable();
        // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
        $changed = $this->serialised($owner, function () use ($owner, $entry, $billable): bool {
            $this->periodLockGuard?->assertWritable($owner, $entry->getScopeKey(), $entry->getStartedAt(), WriteKind::Edit);
            if (!$entry->markBillable($billable)) {
                return false;
            }
            $this->entries->save($entry);

            return true;
        });
        if (!$changed) {
            return $entry;
        }

        try {
            $this->eventDispatcher?->dispatch(
                new TimeEntryBillableChangedEvent($entry->getId(), $entry->getUserId(), UserIdResolver::getId($actor), $old, $billable, StorageTime::normalize($this->clock->now()), $via),
                TimeTrackEvents::TIME_ENTRY_BILLABLE_CHANGED,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack billable-changed listener failed.', ['bundle' => 'time_track', 'action' => 'entry_billable', 'exception' => $exception::class]);
        }
        $this->logger->info('TimeTrack entry billable flag changed.', ['bundle' => 'time_track', 'action' => 'entry_billable', 'via' => $via]);

        return $entry;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function serialised(UserInterface $owner, callable $operation): mixed
    {
        return $this->entries instanceof UserSerialisedTimeEntryRepositoryInterface ? $this->entries->inUserTransaction($owner, $operation) : $operation();
    }
}
