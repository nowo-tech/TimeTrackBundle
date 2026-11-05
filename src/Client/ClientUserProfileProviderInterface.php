<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Optional host data of `GET me` (contract v0.2 §3/§4): a display name and the working hours the clients use to
 * suppress reminders outside them (right to disconnect; the API never pushes). Default:
 * {@see NullClientUserProfileProvider} (both null).
 */
interface ClientUserProfileProviderInterface
{
    /**
     * Name shown by the clients (null = none).
     */
    public function getDisplayName(UserInterface $user): ?string;

    /**
     * Working hours (null = unknown), in either shape:
     *
     * - `{timezone: "Europe/Madrid", slots: [{day: 1, from: "09:00", to: "17:00"}, ...]}` (`day`: ISO weekday,
     *   1 = Monday; since 1.12 the shape `GET me` sends), or
     * - `{timezone: "Europe/Madrid", days: {mon: [["09:00", "17:00"]], ...}}` (the 1.9 shape, still accepted).
     *
     * Since 1.12 `GET me` normalises the value into the `slots` shape ({@see ClientWorkingHoursNormalizer}: overnight
     * ranges split at midnight, overlaps merged); a malformed value fails closed as `{timezone, slots: []}` (never inside
     * working hours) with a warning log. Return null only when the hours are unknown.
     *
     * @return array<string, mixed>|null
     */
    public function getWorkingHours(UserInterface $user): ?array;
}
