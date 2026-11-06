<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.14: calendar providers of the calendar suggestions (contract §6.2, Q-O5): Google Calendar and Microsoft 365.
 */
enum CalendarProvider: string
{
    case Google    = 'google';
    case Microsoft = 'microsoft';
}
