<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeImmutable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.12: optional extra of the `client_api.profile_provider` service, in the style of
 * {@see ClientUserLocaleProviderInterface}. When the profile provider also implements this interface, `GET me` sends
 * the user's holidays and vacations as `daysOff` (the clients send no working-hours reminders on them). Without it, or
 * when it returns null, `daysOff` is null (unknown). The default {@see NullClientUserProfileProvider} returns null.
 *
 * The output is validated by {@see ClientDaysOffNormalizer}: a malformed list makes `daysOff` null (fail closed).
 */
interface ClientUserDaysOffProviderInterface
{
    /**
     * Days off of the user between `$from` (start of today) and `$to` (end of today + 60 days), both in the user's
     * working-hours time zone (the PHP default time zone when the working hours have none).
     *
     * Each entry: `['date' => 'YYYY-MM-DD', 'kind' => 'holiday'|'vacation', 'label' => ?string]` — `date` is a local
     * date in that time zone, `label` is optional plain text (at most {@see ClientDaysOffNormalizer::LABEL_MAX_LENGTH}
     * characters are sent). Dates outside the range are dropped; null = unknown.
     *
     * @return list<array{date: string, kind: string, label?: string|null}>|null
     */
    public function getDaysOff(UserInterface $user, DateTimeImmutable $from, DateTimeImmutable $to): ?array;
}
