<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.14: the user's working hours for the calendar sync (contract §6.7, AC-19: the scheduled sync reads a worker's
 * calendar only inside their working hours) and the zone dailies are grouped in. Fails closed: unknown or malformed
 * working hours are never "inside".
 */
final readonly class CalendarWorkingHours
{
    /**
     * Receives the host profile provider (1.12 working hours).
     */
    public function __construct(
        private ClientUserProfileProviderInterface $profileProvider,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether `$now` falls in one of the user's working-hours slots.
     */
    public function contains(UserInterface $user, DateTimeImmutable $now): bool
    {
        $hours = ClientWorkingHoursNormalizer::normalize($this->profileProvider->getWorkingHours($user), $this->logger);
        if ($hours === null) {
            return false;
        }
        $local = $now->setTimezone(new DateTimeZone($hours['timezone'] ?? 'UTC'));
        $day   = (int) $local->format('N');
        $time  = $local->format('H:i');
        foreach ($hours['slots'] as $slot) {
            if ($slot['day'] === $day && $slot['from'] <= $time && ($time < $slot['to'] || $slot['to'] === '23:59')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user's working-hours zone (UTC when unknown).
     */
    public function timezone(UserInterface $user): DateTimeZone
    {
        $hours = ClientWorkingHoursNormalizer::normalize($this->profileProvider->getWorkingHours($user), $this->logger);

        return new DateTimeZone($hours['timezone'] ?? 'UTC');
    }
}
