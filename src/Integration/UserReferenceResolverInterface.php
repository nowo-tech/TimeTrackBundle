<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\Doctrine\DoctrineUserReferenceResolver;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 1.18: turns the security user a service was called with into the object stored in the bundle's `user`
 * associations (time entries, timers, timesheets, ledger lines, client tokens, device authorizations, calendar rows).
 *
 * The default {@see DoctrineUserReferenceResolver} keeps a `user_class` entity as it is
 * and turns any other security user (a wrapper or DTO of a hexagonal application) into
 * `EntityManager::getReference($userClass, $user->getId())`, so only `getId()` is required of the security user.
 * Alias this interface to your own service to resolve the reference differently.
 *
 * @deprecated since 1.23.0, implement {@see UserReferenceProviderInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface UserReferenceResolverInterface
{
    /**
     * The managed `user_class` entity, or a reference to it, that stands for this security user.
     */
    public function reference(UserInterface $user): object;
}
