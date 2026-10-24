<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

use function password_verify;

/**
 * Default client login: load user by identifier and verify password hash.
 */
final readonly class DefaultClientAuthenticator implements ClientAuthenticatorInterface
{
    /**
     * Bcrypt hash (cost 13, Symfony's `auto` default) verified when the account is unknown so the
     * response time does not reveal whether the username exists.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$13$Qk4VtvdgiABDW3J86AfhyOxz.VzmFh25IEiTDiS6ILwvwHEo.AMgS';

    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private UserProviderInterface $userProvider,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function authenticate(string $username, string $password): ?ClientAuthResult
    {
        try {
            $user = $this->userProvider->loadUserByIdentifier($username);
        } catch (UserNotFoundException) {
            $this->verifyDummyPassword($password);

            return null;
        }

        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            $this->verifyDummyPassword($password);

            return null;
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return null;
        }

        return ClientAuthResult::success($user);
    }

    private function verifyDummyPassword(string $password): void
    {
        password_verify($password, self::DUMMY_PASSWORD_HASH);
    }
}
