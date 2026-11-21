<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.35.0 (audit YI L1): a host user entity with a string primary key in the relation mapping. The `user_id` columns of
 * the bundle then keep the database's default collation (case-insensitive on MySQL / MariaDB), the case the byte-exact
 * owner checks exist for.
 */
#[ORM\Entity]
#[ORM\Table(name: 'string_id_user')]
class StringIdUser implements UserInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $id,
    ) {
    }

    /** @return non-empty-string */
    public function getId(): string
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
