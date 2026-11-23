<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\RemovedPorts\DecoratorOfTaskAccess;

use Nowo\TimeTrackBundle\Security\TaskAccessPolicyInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): a host restriction still decorating the removed task access checker id.
 */
#[AsDecorator('Nowo\TimeTrackBundle\Security\TaskAccessCheckerInterface')]
final class RestrictedTaskAccess implements TaskAccessPolicyInterface
{
    public function mayTrack(TimeTrackUserRef $user, string $taskId): bool
    {
        return false;
    }
}
