<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\ImplementsScopeProvider;

use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered, NOT loadable): a host class that still implements only the removed timesheet scope
 * provider interface.
 */
final class OldScopes implements TimesheetScopeProviderInterface
{
}
