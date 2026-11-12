<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Workday;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see WorkdayProviderInterface} (REQ-ARCH-001 rule 3), same
 * `client_api.workday_provider` option: the clients' access to the host's working-time register.
 */
interface WorkdaySourceInterface
{
    /**
     * Today's working day, or null when the register is off for this user (404).
     */
    public function todayFor(TimeTrackUserRef $user): ?WorkdayState;

    /**
     * Applies an action (`$occurredAt` null = now; the bundle already refused future times and times older than 72 h).
     *
     * @throws WorkdayConflictException When the action is not allowed in the current state (409)
     * @throws WorkdayForbiddenException When the user may not act (403)
     */
    public function actFor(TimeTrackUserRef $user, WorkdayAction $action, ?DateTimeImmutable $occurredAt, string $idempotencyKey, ClientType $client): WorkdayState;
}
