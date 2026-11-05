<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use DateTimeZone;
use Exception;
use Nowo\TimeTrackBundle\Client\ClientUserProfileProviderInterface;
use Nowo\TimeTrackBundle\Client\ClientWorkingHoursNormalizer;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * Since 1.13: the IANA zone a user's periods and working days are cut in, per scope: the scope's zone
 * ({@see TimesheetScope::$timezone}), else the user's working-hours zone (`client_api.profile_provider`), else
 * `timesheets.default_timezone`. An invalid zone is skipped.
 */
final readonly class TimesheetTimezoneResolver
{
    /**
     * @param string $defaultTimezone `timesheets.default_timezone`
     */
    public function __construct(
        private ?TimesheetScopeProviderInterface $scopeProvider = null,
        private ?ClientUserProfileProviderInterface $profileProvider = null,
        private string $defaultTimezone = 'UTC',
    ) {
    }

    /**
     * The zone of the user's periods in the scope.
     */
    public function resolve(UserInterface $user, string $scopeKey): string
    {
        foreach ($this->scopeProvider?->scopesFor($user) ?? [] as $scope) {
            if ($scope->key === $scopeKey && self::isValid($scope->timezone)) {
                return (string) $scope->timezone;
            }
        }

        $hours = $this->profileProvider instanceof ClientUserProfileProviderInterface ? ClientWorkingHoursNormalizer::normalize($this->profileProvider->getWorkingHours($user)) : null;
        $zone  = $hours['timezone'] ?? null;

        return self::isValid($zone) ? (string) $zone : $this->defaultTimezone;
    }

    /**
     * Whether a value is a zone PHP knows.
     */
    public static function isValid(mixed $timezone): bool
    {
        if (!is_string($timezone) || $timezone === '') {
            return false;
        }
        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return false;
        }

        return true;
    }
}
