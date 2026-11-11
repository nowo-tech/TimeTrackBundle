<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use function get_debug_type;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * Since 1.18: default {@see UserReferenceResolverInterface}. A security user that is the `user_class` entity is
 * stored as it is (unchanged behaviour); another security user becomes the `user_class` entity with the same id in the
 * bundle's entity manager, so the security user does not have to be the managed entity.
 *
 * Since 1.19 (audit JV-T3): only security users of a class listed in `accepted_user_classes` are converted (default:
 * none, so only `user_class` instances are stored), and the id must have a `user_class` row: an unknown id is refused
 * before anything is written, also on databases that do not enforce the foreign keys. Both refusals throw
 * {@see AccessDeniedException} (403). The id must still be the `user_class` id of the same person (same value and
 * string form as the entity's `getId()`); `0`, negative ids, null and empty strings are refused.
 */
final readonly class DoctrineUserReferenceResolver implements UserReferenceResolverInterface
{
    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager (`database.entity_manager`)
     * @param class-string $userClass The configured `user_class`
     * @param list<class-string> $acceptedUserClasses Since 1.19: `accepted_user_classes`, the other security user
     *                                                classes (wrappers, DTOs) whose `getId()` is a `user_class` id
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $userClass,
        private array $acceptedUserClasses = [],
    ) {
    }

    /**
     * The user itself when it is a `user_class` instance, else the `user_class` entity with its `getId()`.
     *
     * @throws AccessDeniedException When the class is not accepted or no `user_class` row has the id
     * @throws LogicException When an accepted security user has no usable `getId()`
     */
    public function reference(UserInterface $user): object
    {
        if ($user instanceof $this->userClass) {
            return $user;
        }
        if (!$this->isAccepted($user)) {
            throw new AccessDeniedException(sprintf('The security user class "%s" is neither the TimeTrack user_class nor listed in nowo_time_track.accepted_user_classes.', get_debug_type($user)));
        }

        $id = method_exists($user, 'getId') ? $user->getId() : null;
        if (!$this->isUsableId($id)) {
            throw new LogicException(sprintf('The security user "%s" is not a "%s" entity and has no usable getId(): implement %s or make getId() return the entity id.', get_debug_type($user), $this->userClass, UserReferenceResolverInterface::class));
        }

        $entity = $this->entityManager->find($this->userClass, $id);
        if ($entity === null) {
            throw new AccessDeniedException(sprintf('No "%s" row has the id of this security user.', $this->userClass));
        }

        return $entity;
    }

    private function isAccepted(UserInterface $user): bool
    {
        foreach ($this->acceptedUserClasses as $class) {
            if ($user instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** A positive int, a string other than '' and '0', or an id object (Uuid, Ulid, value object). */
    private function isUsableId(mixed $id): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }
        if (is_string($id)) {
            return $id !== '' && $id !== '0';
        }

        return is_object($id);
    }
}
