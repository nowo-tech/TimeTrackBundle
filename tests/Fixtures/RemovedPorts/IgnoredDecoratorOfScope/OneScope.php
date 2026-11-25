<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\IgnoredDecoratorOfScope;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): a decorator of the removed timesheet scope provider id with
 * `onInvalid: IGNORE` (Symfony would remove it silently).
 */
#[AsDecorator('Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class OneScope
{
}
