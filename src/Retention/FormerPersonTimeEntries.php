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
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function is_string;

use const DATE_ATOM;

/**
 * Since 1.15 (owner rule 2026-11-06): tracked time is not lost, including when a user is deleted. What happens to a
 * person's entries when they leave:
 *
 * - **release** keeps the entries under the person's name, with no user;
 * - **anonymise** keeps the time and clears the person (name, reference, description, metadata): the erasure path.
 *   An anonymised entry is never written again, so a re-sync cannot bring the name back.
 *
 * Every write is a bulk statement with no flush: safe inside a host's erasure transaction. The host authorises every
 * call. Logs carry counts only.
 */
final readonly class FormerPersonTimeEntries
{
    /** Entries read per query by {@see self::export()}. */
    public const EXPORT_BATCH = 500;

    private FormerPersonTimeEntryRepositoryInterface $repository;

    /**
     * @param TimeEntryRepositoryInterface $repository Entry store (must implement {@see FormerPersonTimeEntryRepositoryInterface})
     * @param ClientUserProfileProviderInterface|null $profiles The display name of a user, when the caller passes none
     */
    public function __construct(
        TimeEntryRepositoryInterface $repository,
        private ?ClientUserProfileProviderInterface $profiles = null,
        private ?ClockInterface $clock = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        if (!$repository instanceof FormerPersonTimeEntryRepositoryInterface) {
            throw new LogicException('Former-person time entries require a repository implementing FormerPersonTimeEntryRepositoryInterface.');
        }
        $this->repository = $repository;
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
     * Detaches the user's entries from the user and keeps them under the name (`$name`, else the profile provider's
     * display name, else none). Returns the entries changed.
     */
    public function release(UserInterface $user, ?string $name = null): int
    {
        return $this->logged('release', $this->repository->releaseOwner(UserIdResolver::getId($user), self::personRefOf($user), $this->nameOf($user, $name)));
    }

    /**
     * What a delete the bundle sees (the entity manager's `remove()`) runs first: the snapshot on the user's entries,
     * with the user still set, since the delete can still fail. The foreign key clears the user. Returns the entries changed.
     */
    public function snapshotBeforeDelete(UserInterface $user): int
    {
        return $this->repository->snapshotOwner(UserIdResolver::getId($user), self::personRefOf($user), $this->nameOf($user, null));
    }

    /**
     * Anonymises the user's entries: the time stays, the person goes. Returns the entries changed.
     */
    public function anonymiseUser(UserInterface $user): int
    {
        return $this->logged('anonymise_user', $this->repository->anonymiseOwner(UserIdResolver::getId($user), $this->now()));
    }

    /**
     * Anonymises the ownerless entries of a person reference (a former person, or {@see self::personRefOf()} of a deleted
     * user). Returns the entries changed.
     */
    public function anonymisePerson(string $personRef): int
    {
        return $this->logged('anonymise_person', $this->repository->anonymisePerson($personRef, $this->now()));
    }

    /**
     * The ownerless entries of a person reference, for the host's GDPR art. 15 / 20 export.
     *
     * @return list<array<string, mixed>>
     */
    public function export(string $personRef): array
    {
        $rows  = [];
        $after = '';
        do {
            $entries = $this->repository->findByPersonRef($personRef, $after, self::EXPORT_BATCH);
            foreach ($entries as $entry) {
                $after  = $entry->getId();
                $note   = ($entry->getMetadata() ?? [])['note'] ?? null;
                $rows[] = [
                    'id'              => $entry->getId(),
                    'personName'      => $entry->getPersonName(),
                    'taskId'          => $entry->getTaskId(),
                    'taskTitle'       => $entry->getTaskTitleSnapshot(),
                    'boardId'         => $entry->getBoardIdSnapshot(),
                    'startedAt'       => $entry->getStartedAt()->format(DATE_ATOM),
                    'endedAt'         => $entry->getEndedAt()->format(DATE_ATOM),
                    'durationSeconds' => $entry->getDurationSeconds(),
                    'source'          => $entry->getSource()->value,
                    'billable'        => $entry->getBillable(),
                    'description'     => $entry->getDescription(),
                    'note'            => is_string($note) ? $note : null,
                ];
            }
        } while (count($entries) === self::EXPORT_BATCH);

        return $rows;
    }

    private function nameOf(UserInterface $user, ?string $name): ?string
    {
        return ImportedTimeEntryPerson::cleanName($name ?? $this->profiles?->getDisplayName($user));
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function logged(string $action, int $count): int
    {
        $this->logger->info('TimeTrack former-person entries changed.', ['bundle' => 'time_track', 'action' => $action, 'count' => $count]);

        return $count;
    }
}
