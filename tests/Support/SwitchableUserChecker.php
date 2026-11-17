<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CredentialsExpiredException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.29: the `main` firewall user checker of the functional kernel (`client_api.user_checker` resolves
 * `security.user_checker.main` to it). Passes everyone until a test disables an identifier: `pre` throws
 * DisabledException in checkPreAuth(), `post` CredentialsExpiredException in checkPostAuth(). Records the checked
 * identifiers only once a test sets `$checked = []` (the worker-mode memory tests run thousands of requests). One
 * instance per test kernel.
 */
final class SwitchableUserChecker implements UserCheckerInterface
{
    /** @var array<string, 'post'|'pre'> */
    private array $refused = [];

    /** @var list<string>|null null = not recording */
    public ?array $checked = null;

    /**
     * @param 'post'|'pre' $phase
     */
    public function refuse(string $identifier, string $phase = 'pre'): void
    {
        $this->refused[$identifier] = $phase;
    }

    public function allow(string $identifier): void
    {
        unset($this->refused[$identifier]);
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if ($this->checked !== null) {
            $this->checked[] = 'pre:' . $user->getUserIdentifier();
        }
        if (($this->refused[$user->getUserIdentifier()] ?? null) === 'pre') {
            throw new DisabledException('Account is disabled.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($this->checked !== null) {
            $this->checked[] = 'post:' . $user->getUserIdentifier();
        }
        if (($this->refused[$user->getUserIdentifier()] ?? null) === 'post') {
            throw new CredentialsExpiredException('Credentials have expired.');
        }
    }
}
