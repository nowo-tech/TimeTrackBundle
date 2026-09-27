<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User whose roles can change, to simulate a managed entity reloaded from the database.
 */
final class MutableRoleUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly string $id,
        public array $roles,
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
        return 'user-' . $this->id;
    }
}
