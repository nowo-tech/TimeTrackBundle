<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;
use function is_a;

/**
 * Since 1.15: when a host user is removed through the entity manager, `remove()` stops and saves their running timer
 * ({@see TimerService::stopForUserDeletion()}), and the flush that deletes them gives their entries the name snapshot
 * (or anonymises them, `retention.anonymise_on_user_delete`), loaded ones and ones inserted in that flush included. The
 * `ON DELETE SET NULL` foreign key then clears the user. A store without the capability only gets the timer stopped: the
 * foreign key still keeps its entries, with no name.
 *
 * A delete that bypasses Doctrine does not get here: the foreign key of the timers table deletes the running timer, and the
 * entries stay without a name or reference, out of reach of any later erasure. Such hosts call
 * {@see FormerPersonTimeEntries::release()} before, which stops the timer too.
 */
final readonly class FormerPersonSnapshotListener
{
    /**
     * @param class-string|string $userClass `user_class`
     */
    public function __construct(
        private FormerPersonTimeEntries $entries,
        private string $userClass,
        private ?TimerService $timers = null,
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
     * Settles the entries of the host users this flush deletes. Nothing is written when no flush happens.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->entries->isAvailable()) {
            return;
        }
        $entityManager = $args->getObjectManager();
        $unitOfWork    = $entityManager->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($this->isUser($entity)) {
                $this->settle($entityManager, $unitOfWork, $entity);
            }
        }
    }

    private function settle(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork, UserInterface $user): void
    {
        $anonymise = $this->entries->anonymisesOnUserDelete();
        $name      = $anonymise ? null : $this->entries->snapshotBeforeDelete($user);
        if ($anonymise) {
            $this->entries->anonymiseBeforeDelete($user);
        }
        $userId   = UserIdResolver::getId($user);
        $metadata = $entityManager->getClassMetadata(TimeEntry::class);
        foreach ([...array_values($unitOfWork->getIdentityMap()[TimeEntry::class] ?? []), ...array_values($unitOfWork->getScheduledEntityInsertions())] as $entry) {
            $owner = $entry instanceof TimeEntry ? $entry->getUser() : null;
            if (!$entry instanceof TimeEntry || !$owner instanceof UserInterface || UserIdResolver::getId($owner) !== $userId) {
                continue;
            }
            if ($anonymise) {
                $entry->anonymiseInPlace($this->entries->now());
            } else {
                $entry->detachDeletedUser(FormerPersonTimeEntries::personRefOf($user), $name);
            }
            $unitOfWork->recomputeSingleEntityChangeSet($metadata, $entry);
        }
    }

    /**
     * @phpstan-assert-if-true UserInterface $entity
     */
    private function isUser(object $entity): bool
    {
        return $entity instanceof UserInterface && is_a($entity, $this->userClass);
    }
}
