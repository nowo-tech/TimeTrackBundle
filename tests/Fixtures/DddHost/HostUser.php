<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\DddHost;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.19 (REQ-ARCH-001): the `user_class` of a DDD / hexagonal host — a plain PHP class with no Doctrine attribute,
 * no bundle base class and no bundle trait, mapped by the host in XML (`doctrine/HostUser.orm.xml`). Not final: Doctrine
 * builds lazy proxies of it.
 */
class HostUser implements UserInterface
{
    /** @phpstan-ignore property.unusedType (Doctrine assigns the generated id) */
    private ?int $id = null;

    /**
     * @param non-empty-string $email
     */
    public function __construct(private readonly string $email)
    {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
