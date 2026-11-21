<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Exception;

use Nowo\TimeTrackBundle\PersonalData\TimeTrackPersonalDataInterface;
use RuntimeException;

/**
 * Since 1.35.0: {@see TimeTrackPersonalDataInterface::erase()} refused, with nothing written. The message is the stable
 * code: `timer_running` (the user's timer runs: stop it first, so the time in progress is saved and cannot bring the
 * person back after the erasure) or `invalid_user_id` (an empty id, or one with a leading or trailing blank or an
 * invisible character, which could reach a look-alike's rows).
 */
final class PersonalDataErasureRefusedException extends RuntimeException
{
    public const TIMER_RUNNING   = 'timer_running';
    public const INVALID_USER_ID = 'invalid_user_id';

    /**
     * @param string $errorCode One of the class constants
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
