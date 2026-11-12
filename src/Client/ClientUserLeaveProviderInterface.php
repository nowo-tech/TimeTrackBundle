<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;

/**
 * Since 1.22 (host, `client_api.leave_provider`; Beacon spec 204 FR-029): whether the user is on leave today, for
 * `GET workday.leave` (`{type, until}`) and `GET me` `features.leave` / `links.leave`. Framework-free (REQ-ARCH-001
 * rule 3): the user is passed as its id string, the answer is a bundle-owned value object.
 *
 * Optional and separate from {@see ClientUserProfileProviderInterface}, {@see ClientUserDaysOffProviderInterface} and
 * {@see WorkdayProviderInterface} (no method is added to them). Without it the client
 * API sends no `leave` key at all, as in 1.21.
 *
 * Privacy: return {@see ClientUserLeave::absent()} for medical / sick leave and for every type the user's devices must
 * not show; never put a reason in a {@see ClientUserLeave::typed()} label. Comments and attachments have no place in
 * the value object. Keep the implementation free of request state (worker mode, kernel reset false): it is called for
 * different users by the same long-lived service.
 */
interface ClientUserLeaveProviderInterface
{
    /**
     * The approved full-day leave covering `$day` for the user, or null when they are not on leave that day.
     *
     * @param string $userId The user's id (the same string as `GET me.userId`)
     * @param DateTimeImmutable $day Start of the workday's date (`GET workday.date`) in the workday time zone
     */
    public function getCurrentLeave(string $userId, DateTimeImmutable $day): ?ClientUserLeave;
}
