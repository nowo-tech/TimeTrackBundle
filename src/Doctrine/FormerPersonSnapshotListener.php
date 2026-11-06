<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\Event\PreRemoveEventArgs;
use Nowo\TimeTrackBundle\Retention\FormerPersonTimeEntries;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_a;

/**
 * Since 1.15: when a host user is removed through the entity manager, their entries get the name snapshot first; the
 * `ON DELETE SET NULL` foreign key then clears the user. A delete that bypasses Doctrine keeps the entries without a
 * name: such hosts call {@see FormerPersonTimeEntries::release()} before.
 */
final readonly class FormerPersonSnapshotListener
{
    /**
     * @param class-string|string $userClass `user_class`
     */
    public function __construct(
        private FormerPersonTimeEntries $entries,
        private string $userClass,
    ) {
    }

    /**
     * Snapshots the entries of a host user being removed; every other entity is ignored.
     */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof UserInterface && is_a($entity, $this->userClass)) {
            $this->entries->snapshotBeforeDelete($entity);
        }
    }
}
