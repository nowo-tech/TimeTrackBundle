<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Workday;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.13 (host, `client_api.workday_provider`; R-GDPR §8): the clients' access to the host's working-time
 * register (Beacon spec 198). The register logic — segments, corrections, chain, export — stays entirely in the host.
 */
interface WorkdayProviderInterface
{
    /**
     * Today's working day, or null when the register is off for this user (404).
     */
    public function today(UserInterface $user): ?WorkdayState;

    /**
     * Applies an action (`$occurredAt` null = now; the bundle already refused future times and times older than 72 h).
     *
     * @throws WorkdayConflictException When the action is not allowed in the current state (409)
     * @throws WorkdayForbiddenException When the user may not act (403)
     */
    public function act(UserInterface $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState;
}
