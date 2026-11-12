<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\UserRef\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.23.0 (`user_mapping: id`, REQ-ARCH-001 rule 2): the bundle-owned row that stands for one host user, known by
 * its class and id only. With the id mapping the bundle tables reference this row (`user_id`, a string column) instead of
 * your users table, so your users need not be Doctrine entities of the bundle's entity manager. Mapped only with
 * `user_mapping: id` (table `<table_prefix>host_users`).
 *
 * It implements `UserInterface` only so the bundle can hand a stored owner to the deprecated ports: the identifier is the
 * id and it has no roles. The framework-free twins receive its `TimeTrackUserRef` (your class and id).
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_track_host_users')]
class HostUser implements UserInterface
{
    /** Longest user id the id mapping stores (ints, UUIDs, ULIDs and other string ids). */
    public const ID_LENGTH = 128;

    /**
     * Creates the reference row of a user.
     *
     * @param string $id the user id as a string (`getId()`)
     * @param string $userClass the user class (`user_class`)
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: self::ID_LENGTH)]
        private string $id,
        #[ORM\Column(name: 'user_class', type: 'string', length: 255)]
        private string $userClass,
    ) {
    }

    /**
     * The user id.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The user class.
     */
    public function getUserClass(): string
    {
        return $this->userClass;
    }

    /**
     * The user id (the bundle has no other identifier for it).
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->id === '' ? '?' : $this->id;
    }

    /**
     * No roles: role checks always read the security user of the request.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [];
    }

    /**
     * Nothing to erase.
     */
    public function eraseCredentials(): void
    {
    }
}
