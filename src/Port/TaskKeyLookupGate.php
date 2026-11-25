<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Calendar\TaskKeyLookupInterface;
use Nowo\TimeTrackBundle\Port\Internal\TaskKeyResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see TaskKeyLookupInterface} (1.23.0) where the bundle asks its internal shape
 * {@see TaskKeyResolverInterface} (the 1.x interface of that name was removed in 2.0). Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class TaskKeyLookupGate implements TaskKeyResolverInterface
{
    /**
     * Wraps the host lookup.
     */
    public function __construct(private TaskKeyLookupInterface $lookup)
    {
    }

    /**
     * The lookup's keys of the title.
     */
    public function extractKeys(string $title): array
    {
        return $this->lookup->extractKeys($title);
    }

    /**
     * The lookup's tasks for the user's reference.
     */
    public function resolve(UserInterface $user, string $scopeKey, array $keys): array
    {
        return $this->lookup->resolveFor(TimeTrackUserRefs::fromUser($user), $scopeKey, $keys);
    }
}
