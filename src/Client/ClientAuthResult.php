<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Outcome of a client login: the authenticated user (with the host credential it used and the scopes it grants), or
 * a failure.
 */
final readonly class ClientAuthResult
{
    /** @var list<ClientScope> */
    private array $scopes;

    /**
     * @param list<ClientScope|string>|null $scopes
     */
    private function __construct(
        private bool $success,
        private ?UserInterface $user = null,
        private ?string $credentialId = null,
        ?array $scopes = null,
    ) {
        $this->scopes = $success ? ClientScope::fromRequested($scopes) : [];
    }

    /**
     * Successful login of the user.
     *
     * @param UserInterface $user Authenticated user
     * @param string|null $credentialId Host credential used (e.g. app password id); 1.x authenticators pass none
     * @param list<ClientScope|string>|null $scopes Granted scopes; null = the default scopes (`timer:read`, `timer:write`, `tasks:read`, `entries:read`; the 1.13 scopes need an explicit grant)
     *
     * @throws InvalidArgumentException When $scopes is an empty list or holds an unknown scope (never widened to every scope)
     */
    public static function success(UserInterface $user, ?string $credentialId = null, ?array $scopes = null): self
    {
        return new self(true, $user, $credentialId === '' ? null : $credentialId, $scopes);
    }

    /**
     * Failed login.
     */
    public static function failure(): self
    {
        return new self(false);
    }

    /**
     * Whether the login succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Authenticated user (null on failure).
     */
    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    /**
     * Host credential id (null when the authenticator did not report one).
     */
    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    /**
     * Granted scopes.
     *
     * @return list<ClientScope>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @throws LogicException when called on a failed result
     */
    public function requireUser(): UserInterface
    {
        if (!$this->success || !$this->user instanceof UserInterface) {
            throw new LogicException('ClientAuthResult has no authenticated user.');
        }

        return $this->user;
    }
}
