<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.29 (`client_api.user_checker`, Beacon C5): runs the Symfony user checker (`checkPreAuth()` then
 * `checkPostAuth()`) for the user of a Bearer token on every use, so tokens of disabled, locked or expired accounts
 * stop working at once, whatever ended the account. With `revoke_tokens` (default) the refused token is deleted, like
 * Beacon's `ActiveAccountClientTokens`: the client signs in again once the account is re-enabled. Without a checker
 * (`client_api.user_checker: false`, or no firewall checker found) every account passes.
 *
 * Stateless (FrankenPHP worker mode without kernel reset): the checker and the store are services; the user was
 * refreshed from the database by {@see ClientAuthService::resolveToken()}.
 *
 * @internal
 */
final readonly class ClientAccountChecker
{
    /**
     * @param UserCheckerInterface|null $userChecker The checker (null = no check)
     * @param ClientTokenRepositoryInterface|null $tokens The token store (deletes refused tokens)
     * @param bool $revokeTokens Delete the token of a refused account
     */
    public function __construct(
        private ?UserCheckerInterface $userChecker = null,
        private ?ClientTokenRepositoryInterface $tokens = null,
        private bool $revokeTokens = true,
    ) {
    }

    /**
     * Whether the token user's account may use the API; deletes the token when refused and `revoke_tokens` is on.
     */
    public function isActive(ClientToken $token, UserInterface $user): bool
    {
        if (!$this->userChecker instanceof UserCheckerInterface) {
            return true;
        }

        try {
            $this->userChecker->checkPreAuth($user);
            $this->userChecker->checkPostAuth($user);
        } catch (AuthenticationException) {
            if ($this->revokeTokens && $this->tokens instanceof ClientTokenRepositoryInterface) {
                // @igor-ignore - Repository call (I/O); no worker state.
                $this->tokens->remove($token);
            }

            return false;
        }

        return true;
    }

    /**
     * Whether a user checker runs (false when turned off or none was found).
     */
    public function isEnabled(): bool
    {
        return $this->userChecker instanceof UserCheckerInterface;
    }
}
