<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Stub;

use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Profile provider whose working hours a test sets (default: every day, all day, UTC).
 */
final class MutableProfileProvider implements ClientUserProfileProviderInterface
{
    /** @var array<string, mixed>|null */
    public ?array $hours = ['timezone' => 'UTC', 'slots' => [
        ['day' => 1, 'from' => '00:00', 'to' => '24:00'], ['day' => 2, 'from' => '00:00', 'to' => '24:00'], ['day' => 3, 'from' => '00:00', 'to' => '24:00'],
        ['day' => 4, 'from' => '00:00', 'to' => '24:00'], ['day' => 5, 'from' => '00:00', 'to' => '24:00'], ['day' => 6, 'from' => '00:00', 'to' => '24:00'],
        ['day' => 7, 'from' => '00:00', 'to' => '24:00'],
    ]];

    public function getDisplayName(UserInterface $user): ?string
    {
        return null;
    }

    public function getWorkingHours(UserInterface $user): ?array
    {
        return $this->hours;
    }
}
