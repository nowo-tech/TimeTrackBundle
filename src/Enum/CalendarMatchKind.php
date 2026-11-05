<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.14: why an event became a suggestion (contract §3.6): a task key in its title, or a user rule (§3.7).
 */
enum CalendarMatchKind: string
{
    case Key  = 'key';
    case Rule = 'rule';
}
