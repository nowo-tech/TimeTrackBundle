<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Access;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.37.0: the actor of an id-based entry check ({@see TimeEntryAccess}) when the host gave an id, or a
 * reference without its user object. It implements `UserInterface` only so the access rules (and the deprecated ports
 * they may ask) can take it, like {@see HostUser}: its id, identifier and roles are
 * the reference's. The framework-free ports receive the reference itself
 * ({@see TimeTrackUserRefFactory::buildFromObject()}). Immutable, built per call.
 *
 * @internal
 */
final readonly class IdActor implements UserInterface
{
    /** @var non-empty-string */
    private string $id;

    /**
     * @throws InvalidArgumentException when the reference has no id
     */
    public function __construct(private TimeTrackUserRef $ref)
    {
        $id = $ref->id ?? '';
        if ($id === '') {
            throw new InvalidArgumentException('An id actor needs a user id.');
        }
        $this->id = $id;
    }

    /**
     * The reference the actor stands for.
     */
    public function ref(): TimeTrackUserRef
    {
        return $this->ref;
    }

    /**
     * The user id.
     *
     * @return non-empty-string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The reference's identifier, else the id.
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->ref->identifier !== '' ? $this->ref->identifier : $this->id;
    }

    /**
     * The role names the reference carries (none for a bare id).
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->ref->roles;
    }

    /**
     * Nothing to erase.
     */
    public function eraseCredentials(): void
    {
    }
}
