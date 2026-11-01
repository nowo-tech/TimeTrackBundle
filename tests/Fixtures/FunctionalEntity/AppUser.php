<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host user entity (`user_class`) of the functional kernel: e-mail login, plaintext test password, roles.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class AppUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /**
     * @param non-empty-string $email
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(length: 180, unique: true)]
        private string $email,
        #[ORM\Column]
        private string $password,
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }
}
