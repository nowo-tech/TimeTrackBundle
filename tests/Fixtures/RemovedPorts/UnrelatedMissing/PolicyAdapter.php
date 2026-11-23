<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\UnrelatedMissing;

use Nowo\TimeTrackBundle\Integration\TaskProviderInterfaceOfAnotherPackage;

/**
 * 2.0 W1-1 fixture (autodiscovered, NOT loadable): an interface that was never part of TimeTrack and whose name only
 * starts like a removed one. The build keeps Symfony's own lazy error (the unused class is dropped).
 */
final class PolicyAdapter implements TaskProviderInterfaceOfAnotherPackage
{
}
