<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.18: stores the security user itself (the behaviour before 1.18). Services built by hand without a
 * {@see UserReferenceResolverInterface} use it; the container wires the Doctrine resolver instead.
 */
final class PassThroughUserReferenceResolver implements UserReferenceResolverInterface
{
    public function reference(UserInterface $user): UserInterface
    {
        return $user;
    }
}
