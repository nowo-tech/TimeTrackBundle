<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * Since 1.14: who reads the calendar (contract §6.1). `server`: a bundle adapter with a host-held token; `local`: the
 * desktop app reads it on the device (the row only records the consent, no credential).
 */
enum CalendarConnectionMode: string
{
    case Server = 'server';
    case Local  = 'local';
}
