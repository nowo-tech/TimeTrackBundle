<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarProviderInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkingCalendarSourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see WorkingCalendarSourceInterface} (1.23.0) where the bundle asks the deprecated
 * {@see WorkingCalendarProviderInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class WorkingCalendarSourceGate implements WorkingCalendarProviderInterface
{
    /**
     * Wraps the host source.
     */
    public function __construct(private WorkingCalendarSourceInterface $source)
    {
    }

    /**
     * The source's days for the user's reference.
     */
    public function days(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        return $this->source->daysFor(TimeTrackUserRefs::fromUser($user), $scopeKey, $from, $to);
    }
}
