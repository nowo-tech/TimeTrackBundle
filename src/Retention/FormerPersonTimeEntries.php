<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Retention;

use DateTimeImmutable;
use LogicException;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Import\ImportedTimeEntryPerson;
use Nowo\TimeTrackBundle\Repository\FormerPersonTimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\TimeEntryRepositoryInterface;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\TimeEntryCsv;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function count;
use function is_string;

use const DATE_ATOM;

/**
 * Since 1.15 (owner rule 2026-11-06): tracked time is not lost, including when a user is deleted. The rule keeps the hours;
 * it does not require keeping the person. What happens to a person's entries when they leave:
 *
 * - **release** keeps the entries under the person's name, with no user;
 * - **anonymise** keeps the time and clears the person: name, reference, description, task title and metadata (bar the
 *   `closed_by` mark), with the external id hashed. An anonymised entry is never written again, so a re-sync cannot bring
 *   the name back. The result is pseudonymised unless the host has assessed it: task id, board, tenant and exact times stay.
 *
 * A name kept has no expiry of its own: the host sets the retention term ({@see self::anonymiseOwnerlessEndedBefore()}).
 * Bulk statements with no flush, except that release and anonymiseUser stop a running timer first, which flushes. The
 * host authorises every call. Logs carry counts only. With a store lacking the capability every method throws except
 * {@see self::isAvailable()}.
 */
final readonly class FormerPersonTimeEntries
{
    /** Entries read per query by {@see self::export()}. */
    public const EXPORT_BATCH = 500;

    private ?FormerPersonTimeEntryRepositoryInterface $repository;

    /**
     * @param TimeEntryRepositoryInterface $repository Entry store ({@see FormerPersonTimeEntryRepositoryInterface} for everything but {@see self::isAvailable()})
     * @param ClientUserProfileProviderInterface|null $profiles The display name of a user, when the caller passes none
     * @param TimerService|null $timers Stops a running timer before a release or an anonymisation
     * @param bool $anonymiseOnUserDelete `retention.anonymise_on_user_delete`
     */
    public function __construct(
        TimeEntryRepositoryInterface $repository,
        private ?ClientUserProfileProviderInterface $profiles = null,
        private ?ClockInterface $clock = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?TimerService $timers = null,
        private bool $anonymiseOnUserDelete = false,
    ) {
        $this->repository = $repository instanceof FormerPersonTimeEntryRepositoryInterface ? $repository : null;
    }

    /**
     * Whether the entry store has {@see FormerPersonTimeEntryRepositoryInterface}. A host's replacement or decorator may not.
     */
    public function isAvailable(): bool
    {
        return $this->repository instanceof FormerPersonTimeEntryRepositoryInterface;
    }

    /**
     * Whether a user removed through the entity manager has their entries anonymised rather than kept under their name.
     */
    public function anonymisesOnUserDelete(): bool
    {
        return $this->anonymiseOnUserDelete;
    }

    /**
     * The reference a user's released entries carry: what {@see self::anonymisePerson()} and {@see self::export()} take
     * once the account is gone.
     */
    public static function personRefOf(UserInterface $user): string
    {
        return TimeEntry::USER_REF_PREFIX . UserIdResolver::getId($user);
    }

    /**
     * Stops a running timer and detaches the user's entries from the user, keeping them under the name (`$name`, else the
     * profile provider's display name, else none). The call a host needs before deleting a user by SQL. Returns the
     * entries changed.
     */
    public function release(UserInterface $user, ?string $name = null): int
    {
        $store = $this->store();
        $this->timers?->stopForUserDeletion($user);

        return $this->logged('release', $store->releaseOwner(UserIdResolver::getId($user), self::personRefOf($user), $this->nameOf($user, $name)));
    }

    /**
     * Part of a delete the bundle sees, before the flush's commit: the name the entries will keep. A provider that fails costs
     * the name, not the delete.
     */
    public function nameBeforeDelete(UserInterface $user): ?string
    {
        return $this->nameOf($user, null);
    }

    /**
     * Part of a delete the bundle sees, before the flush's commit: the entries to settle after it. Reads only.
     *
     * @return list<string>
     */
    public function entryIdsOf(UserInterface $user): array
    {
        return $this->store()->entryIdsOfOwner(UserIdResolver::getId($user));
    }

    /**
     * Part of a delete the bundle sees, after the commit: the name and reference on the entries of the deleted user.
     *
     * @param list<string> $entryIds
     */
    public function settleDeleted(array $entryIds, string $personRef, ?string $name): int
    {
        return $this->store()->settleDeletedOwner($entryIds, $personRef, $name);
    }

    /**
     * Part of a delete the bundle sees, after the commit, with `retention.anonymise_on_user_delete`.
     *
     * @param list<string> $entryIds
     */
    public function anonymiseDeleted(array $entryIds): int
    {
        return $this->logged('anonymise_on_delete', $this->store()->anonymiseEntries($entryIds, $this->now()));
    }

    /**
     * The erasure of a user: stops a running timer so it cannot bring the person back later, then anonymises every entry of
     * theirs, those already released included. The time stays, the person goes. Returns the entries changed.
     */
    public function anonymiseUser(UserInterface $user): int
    {
        $store = $this->store();
        $this->timers?->stopForUserDeletion($user);

        return $this->logged('anonymise_user', $store->anonymiseOwner(UserIdResolver::getId($user), $this->now()) + $store->anonymisePerson(self::personRefOf($user), $this->now()));
    }

    /**
     * Anonymises the ownerless entries of a person reference (a former person, or {@see self::personRefOf()} of a deleted
     * user). Returns the entries changed.
     */
    public function anonymisePerson(string $personRef): int
    {
        return $this->logged('anonymise_person', $this->store()->anonymisePerson($personRef, $this->now()));
    }

    /**
     * Anonymises the ownerless entries with no reference: what a raw `DELETE` with no release before it leaves. Nothing ties
     * them to anyone, so an erasure request cannot pick one person's: this takes them all. Returns the entries changed.
     */
    public function anonymiseUnidentified(): int
    {
        return $this->logged('anonymise_unidentified', $this->store()->anonymiseUnidentified($this->now()));
    }

    /**
     * Anonymises the ownerless entries that ended before the cut-off: the retention term for names and free text, which the
     * bundle does not choose for the host. Returns the entries changed.
     */
    public function anonymiseOwnerlessEndedBefore(DateTimeImmutable $cutOff): int
    {
        return $this->logged('anonymise_retention', $this->store()->anonymiseOwnerlessEndedBefore($cutOff, $this->now()));
    }

    /**
     * The ownerless entries of a person reference, for the host's GDPR art. 15 / 20 export. Cells hold the stored text: a host
     * writing a CSV passes them through {@see TimeEntryCsv::cell()}. A row is matched again in PHP: a MySQL collation may fold case.
     *
     * @return list<array<string, mixed>>
     */
    public function export(string $personRef): array
    {
        $rows  = [];
        $after = '';
        do {
            $entries = $this->store()->findByPersonRef($personRef, $after, self::EXPORT_BATCH);
            foreach ($entries as $entry) {
                $after = $entry->getId();
                if ($entry->getPersonRef() !== $personRef) {
                    // Needs a case-insensitive collation: testReferencesThatDifferOnlyByCase… on MySQL in CI.
                    // @codeCoverageIgnoreStart
                    continue;
                    // @codeCoverageIgnoreEnd
                }
                $note   = ($entry->getMetadata() ?? [])['note'] ?? null;
                $rows[] = [
                    'id'                   => $entry->getId(),
                    'personName'           => $entry->getPersonName(),
                    'taskId'               => $entry->getTaskId(),
                    'taskTitle'            => $entry->getTaskTitleSnapshot(),
                    'boardId'              => $entry->getBoardIdSnapshot(),
                    'startedAt'            => $entry->getStartedAt()->format(DATE_ATOM),
                    'endedAt'              => $entry->getEndedAt()->format(DATE_ATOM),
                    'durationSeconds'      => $entry->getDurationSeconds(),
                    'source'               => $entry->getSource()->value,
                    'billable'             => $entry->getBillable(),
                    'description'          => $entry->getDescription(),
                    'note'                 => is_string($note) ? $note : null,
                    'closedByUserDeletion' => $entry->isClosedByUserDeletion(),
                ];
            }
        } while (count($entries) === self::EXPORT_BATCH);

        return $rows;
    }

    /**
     * The current time, by the service's clock.
     */
    public function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function store(): FormerPersonTimeEntryRepositoryInterface
    {
        return $this->repository ?? throw new LogicException('Former-person time entries require a repository implementing FormerPersonTimeEntryRepositoryInterface.');
    }

    private function nameOf(UserInterface $user, ?string $name): ?string
    {
        if ($name !== null) {
            return ImportedTimeEntryPerson::cleanName($name);
        }
        try {
            return ImportedTimeEntryPerson::cleanName($this->profiles?->getDisplayName($user));
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack could not read a display name; the entries stay with no name.', ['bundle' => 'time_track', 'action' => 'former_person_name', 'error' => $exception::class]);

            return null;
        }
    }

    private function logged(string $action, int $count): int
    {
        $this->logger->info('TimeTrack former-person entries changed.', ['bundle' => 'time_track', 'action' => $action, 'count' => $count]);

        return $count;
    }
}
