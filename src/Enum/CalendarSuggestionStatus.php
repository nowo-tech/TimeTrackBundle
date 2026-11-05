<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.14: state of a calendar suggestion (contract §3.6).
 */
enum CalendarSuggestionStatus: string
{
    case Pending   = 'pending';
    case Accepted  = 'accepted';
    case Dismissed = 'dismissed';
    case Expired   = 'expired';
}
