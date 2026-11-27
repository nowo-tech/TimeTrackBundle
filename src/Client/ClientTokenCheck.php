<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.2.0: the outcome of the stateless token checks of {@see ClientApiGuard::check()} — the token, its user and
 * the allowed tenant context, or the error code (`invalid_token`, `insufficient_scope`, `rate_limited`,
 * `context_forbidden`, `context_required`, since 2.4.0 `module_forbidden`) with its Retry-After. Built per request, never kept.
 *
 * @internal
 */
final readonly class ClientTokenCheck
{
    /**
     * @param string|null $requiredScope The scope that was missing (`insufficient_scope`), or the family (`module_forbidden`)
     */
    private function __construct(
        public ?ClientToken $token,
        public ?UserInterface $user,
        public ?string $contextKey,
        public ?string $error,
        public int $retryAfter = 0,
        public ?string $requiredScope = null,
    ) {
    }

    /**
     * An authenticated request.
     */
    public static function passed(ClientToken $token, UserInterface $user, ?string $contextKey): self
    {
        return new self($token, $user, $contextKey, null);
    }

    /**
     * A refused request.
     */
    public static function refused(string $error, int $retryAfter = 0, ?string $requiredScope = null, ?ClientToken $token = null): self
    {
        return new self($token, null, null, $error, $retryAfter, $requiredScope);
    }
}
