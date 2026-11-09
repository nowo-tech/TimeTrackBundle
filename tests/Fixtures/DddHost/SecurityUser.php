<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\DddHost;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Firewall user of a hexagonal host (1.18): not the `user_class` Doctrine entity, it only carries the user's id.
 */
final readonly class SecurityUser implements UserInterface
{
    /**
     * @param non-empty-string $identifier
     */
    public function __construct(private int|string|object|null $id, private string $identifier = 'security-user')
    {
    }

    public function getId(): int|string|object|null
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

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
