<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.22 (audit NG-1): a host profile provider whose days off hold a holiday, a `leave` day with a private label (it
 * must never reach a client) and a vacation, on the first three days of the requested range. No working hours.
 */
final class LeaveDaysOffProfileProvider implements ClientUserProfileProviderInterface, ClientUserDaysOffProviderInterface
{
    public function getDisplayName(UserInterface $user): ?string
    {
        return null;
    }

    public function getWorkingHours(UserInterface $user): ?array
    {
        return null;
    }

    public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            ['date' => $from->format('Y-m-d'), 'kind' => 'holiday', 'label' => 'Inmaculada Concepción'],
            ['date' => $from->modify('+1 day')->format('Y-m-d'), 'kind' => 'leave', 'label' => 'Baja médica: gripe'],
            ['date' => $from->modify('+2 days')->format('Y-m-d'), 'kind' => 'vacation', 'label' => null],
        ];
    }
}
