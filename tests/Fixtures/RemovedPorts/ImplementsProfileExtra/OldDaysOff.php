<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\ImplementsProfileExtra;

use Nowo\TimeTrackBundle\Client\ClientUserDaysOffProviderInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered, NOT loadable): a host class that still implements the removed days-off extra of
 * the profile provider.
 */
final class OldDaysOff implements ClientUserDaysOffProviderInterface
{
}
