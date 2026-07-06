<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TestUser implements UserInterface
{
    public function __construct(
        private string $id,
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

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
