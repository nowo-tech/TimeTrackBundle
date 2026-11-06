<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\Event\PreRemoveEventArgs;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Nowo\TimeTrackBundle\Service\TimerService;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_a;

/**
 * Since 1.15: when a host user is removed through the entity manager, their running timer is stopped and saved
 * ({@see TimerService::stopForUserDeletion()}) and their entries, that one included, get the name snapshot; the `ON DELETE
 * SET NULL` foreign key then clears the user. A delete that bypasses Doctrine does not get here: the foreign key of the
 * timers table deletes the running timer, and the entries stay without a name. Such hosts call
 * {@see TimerService::stopForUserDeletion()} and {@see FormerPersonTimeEntries::release()} before.
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
     * Saves the running timer and snapshots the entries of a host user being removed; every other entity is ignored.
     */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof UserInterface && is_a($entity, $this->userClass)) {
            $this->timers?->stopForUserDeletion($entity);
            $this->entries->snapshotBeforeDelete($entity);
        }
    }
}
