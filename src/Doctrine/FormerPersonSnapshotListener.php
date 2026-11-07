<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_values;
use function is_a;
use function spl_object_id;

/**
 * Since 1.15: when a host user is removed through the entity manager, `remove()` stops and saves their running timer
 * ({@see TimerService::stopForUserDeletion()}). The flush that deletes them changes loaded entries, and ones inserted in that
 * flush, inside its own transaction. The other entries are written only after the commit, in `postFlush`: the name snapshot,
 * or the anonymisation with `retention.anonymise_on_user_delete`. So a delete that fails changes nothing on a living user's
 * rows. If the process dies between the commit and `postFlush`, the foreign key has kept those rows with no name or
 * reference: {@see FormerPersonTimeEntries::anonymiseUnidentified()} reaches them. A store without the capability only gets
 * the timer stopped.
 *
 * A delete that runs no bundle code (bulk DQL, raw SQL, a database cascade from a parent table) does not get here: the
 * foreign key of the timers table deletes the running timer, and the entries stay with no name or reference, out of reach of
 * any later erasure of one person. Such hosts call {@see FormerPersonTimeEntries::release()} before, which stops the timer too.
 *
 * A timer started between `remove()` and the flush is deleted with the user.
 */
final class FormerPersonSnapshotListener implements ResetInterface
{
    /**
     * What each entity manager's flush settles after its commit. State between `onFlush` and `postFlush` of one flush only:
     * `onFlush` starts it again, so a failed flush leaves nothing behind, and {@see self::reset()} clears it between requests.
     *
     * @var array<int, list<array{userId: string, loaded: list<TimeEntry>, ids: list<string>, personRef: string, name: string|null, anonymise: bool}>>
     */
    private array $pending = [];

    /**
     * @param class-string $userClass `user_class`
     */
    public function __construct(
        private readonly FormerPersonTimeEntries $entries,
        private readonly string $userClass,
        private readonly ?TimerService $timers = null,
    ) {
    }

    /**
     * Saves the running timer of a host user being removed; every other entity is ignored.
     */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->isUser($entity)) {
            $this->timers?->stopForUserDeletion($entity);
        }
    }

    /**
     * Prepares the entries of the host users this flush deletes. It writes no row outside the flush's own transaction.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->entries->isAvailable()) {
            return;
        }
        $entityManager = $args->getObjectManager();
        $unitOfWork    = $entityManager->getUnitOfWork();
        $key           = spl_object_id($entityManager);
        unset($this->pending[$key]);
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($this->isUser($entity)) {
                $this->prepare($entityManager, $unitOfWork, $entity, $key);
            }
        }
    }

    /**
     * After the commit: the user is gone, so the other entries are settled. Since 1.16 (HA-L19) only if the row really is gone:
     * a `remove()` inside another listener's `onFlush`, with nothing else to flush, deletes nothing. Loaded entries prepared
     * for a delete that did not happen are read again.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $key     = spl_object_id($args->getObjectManager());
        $pending = $this->pending[$key] ?? [];
        unset($this->pending[$key]);
        $entityManager = $args->getObjectManager();
        foreach ($pending as $settle) {
            if ($this->userStillExists($entityManager, $settle['userId'])) {
                foreach ($settle['loaded'] as $entry) {
                    $entityManager->refresh($entry);
                }
                continue;
            }
            if ($settle['anonymise']) {
                $this->entries->anonymiseDeleted($settle['ids']);
            } else {
                $this->entries->settleDeleted($settle['ids'], $settle['personRef'], $settle['name']);
            }
        }
    }

    /**
     * Forgets what a failed flush left (worker mode).
     */
    public function reset(): void
    {
        $this->pending = [];
    }

    private function prepare(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork, UserInterface $user, int $key): void
    {
        $anonymise = $this->entries->anonymisesOnUserDelete();
        $name      = $anonymise ? null : $this->entries->nameBeforeDelete($user);
        $personRef = FormerPersonTimeEntries::personRefOf($user);
        $userId    = UserIdResolver::getId($user);
        $metadata  = $entityManager->getClassMetadata(TimeEntry::class);

        $ids    = $this->entries->entryIdsOf($user);
        $loaded = [];
        foreach ([...array_values($unitOfWork->getIdentityMap()[TimeEntry::class] ?? []), ...array_values($unitOfWork->getScheduledEntityInsertions())] as $entry) {
            $owner = $entry instanceof TimeEntry ? $entry->getUser() : null;
            if (!$entry instanceof TimeEntry || !$owner instanceof UserInterface || UserIdResolver::getId($owner) !== $userId) {
                continue;
            }
            if ($anonymise) {
                $entry->anonymiseInPlace($this->entries->now());
            } else {
                $entry->detachDeletedUser($personRef, $name);
            }
            $unitOfWork->recomputeSingleEntityChangeSet($metadata, $entry);
            if ($unitOfWork->isInIdentityMap($entry) && !$unitOfWork->isScheduledForInsert($entry)) {
                $loaded[] = $entry;
            }
        }
        $this->pending[$key][] = ['userId' => $userId, 'loaded' => $loaded, 'ids' => $ids, 'personRef' => $personRef, 'name' => $name, 'anonymise' => $anonymise];
    }

    private function userStillExists(EntityManagerInterface $entityManager, string $userId): bool
    {
        return (int) $entityManager->createQueryBuilder()
            ->select('COUNT(u)')
            ->from($this->userClass, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @phpstan-assert-if-true UserInterface $entity
     */
    private function isUser(object $entity): bool
    {
        return $entity instanceof UserInterface && is_a($entity, $this->userClass);
    }
}
