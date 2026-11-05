<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Profile provider with working hours and days off; remembers the range it was asked for.
 */
final class DaysOffProfileProvider implements ClientUserProfileProviderInterface, ClientUserDaysOffProviderInterface
{
    public ?DateTimeImmutable $from = null;

    public ?DateTimeImmutable $to = null;

    /**
     * @param array<mixed>|null $hours
     * @param list<array{date: string, kind: string, label?: string|null}>|null $daysOff
     */
    public function __construct(private readonly ?array $hours, private readonly ?array $daysOff)
    {
    }

    public function getDisplayName(UserInterface $user): ?string
    {
        return null;
    }

    public function getWorkingHours(UserInterface $user): ?array
    {
        return $this->hours;
    }

    public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $this->from = $from;
        $this->to   = $to;

        /* @var list<array{date: string, kind: string, label?: string|null}>|null */
        return $this->daysOff;
    }
}
