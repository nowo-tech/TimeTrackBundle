<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeRecordInterface;
use Nowo\TimeTrackBundle\WorkingTime\WorkedTimeSourceInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see WorkedTimeRecordInterface} (1.23.0) where the bundle asks the deprecated
 * {@see WorkedTimeSourceInterface}. Exceptions propagate. Built by {@see Ports}; stateless.
 *
 * @internal
 */
final readonly class WorkedTimeRecordGate implements WorkedTimeSourceInterface
{
    /**
     * Wraps the host record.
     */
    public function __construct(private WorkedTimeRecordInterface $record)
    {
    }

    /**
     * Whether the record is the legal register.
     */
    public function isLegalRecord(): bool
    {
        return $this->record->isLegalRecord();
    }

    /**
     * The record's worked days for the user's reference.
     */
    public function workedDays(UserInterface $user, string $scopeKey, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->record->workedDaysOf(TimeTrackUserRefs::fromUser($user), $scopeKey, $from, $to);
    }
}
