<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TestUser implements UserInterface
{
    public function __construct(
        private string $id,
        /** @var non-empty-string */
        private string $identifier,
        /** @var list<string> */
        private array $roles = ['ROLE_USER'],
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function eraseCredentials(): void
    {
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
