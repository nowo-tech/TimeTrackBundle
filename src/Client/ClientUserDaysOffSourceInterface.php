<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see ClientUserDaysOffProviderInterface} (REQ-ARCH-001 rule 3), an optional
 * extra of the `client_api.profile_provider` service. Same contract and privacy rules.
 */
interface ClientUserDaysOffSourceInterface
{
    /**
     * Days off of the user between `$from` and `$to` (see {@see ClientUserDaysOffProviderInterface::getDaysOff()}).
     *
     * @return list<array{date: string, kind: string, label?: string|null}>|null
     */
    public function daysOffOf(TimeTrackUserRef $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array;
}
