<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function get_debug_type;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function sprintf;

/**
 * Since 1.18: default {@see UserReferenceResolverInterface}. A security user that is the `user_class` entity is
 * stored as it is (unchanged behaviour); any other security user becomes a Doctrine reference built from its
 * `getId()` in the bundle's entity manager, so the security user does not have to be the managed entity.
 *
 * The id must be the `user_class` id of the same person (same value and same string form as the entity's `getId()`):
 * the resolver cannot tell users of another identity domain apart. `0`, negative ids, null and empty strings are
 * refused; an id with no row fails at flush on a database that enforces the foreign keys.
 */
final readonly class DoctrineUserReferenceResolver implements UserReferenceResolverInterface
{
    /**
     * @param EntityManagerInterface $entityManager The bundle's entity manager (`database.entity_manager`)
     * @param class-string $userClass The configured `user_class`
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $userClass,
    ) {
    }

    /**
     * The user itself when it is a `user_class` instance, else a reference built from its `getId()`.
     */
    public function reference(UserInterface $user): object
    {
        if ($user instanceof $this->userClass) {
            return $user;
        }

        $id = method_exists($user, 'getId') ? $user->getId() : null;
        if (!$this->isUsableId($id)) {
            throw new LogicException(sprintf('The security user "%s" is not a "%s" entity and has no usable getId(): implement %s or make getId() return the entity id.', get_debug_type($user), $this->userClass, UserReferenceResolverInterface::class));
        }

        $reference = $this->entityManager->getReference($this->userClass, $id);
        if ($reference === null) {
            throw new LogicException(sprintf('No Doctrine reference could be built for "%s".', $this->userClass));
        }

        return $reference;
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
