<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function trim;

/**
 * Public, stable way (since 1.24.0) for a host to build the framework-free {@see TimeTrackUserRef} of a Symfony
 * `UserInterface`: autowire it by its class (public service) or call {@see self::build()} where no container is at hand.
 *
 * `TimeTrackUserRef` itself stays free of Symfony (REQ-ARCH-001 rule 3), so the Symfony-aware named constructor lives
 * here, in the bridge layer. The bundle's own conversion ({@see TimeTrackUserRefs::fromUser()}) calls the same code, so
 * a host's ref and the bundle's ref of the same user are equal. Stateless: no property, nothing kept between calls
 * (FrankenPHP worker mode, kernel reset false).
 */
final readonly class TimeTrackUserRefFactory
{
    /**
     * The reference of a Symfony user: its class (no Doctrine proxy), `UserIdResolver::idOf()` (null when none or blank),
     * its identifier and role names, and the user object itself (hidden, see {@see TimeTrackUserRef}).
     */
    public function fromUser(UserInterface $user): TimeTrackUserRef
    {
        return self::build($user);
    }

    /**
     * Static twin of {@see self::fromUser()} for code without the service.
     */
    public static function build(UserInterface $user): TimeTrackUserRef
    {
        return self::buildFromObject($user);
    }

    /**
     * Shared derivation, also used for non-Symfony objects and stored host users by the bundle.
     *
     * @internal
     */
    public static function buildFromObject(object $user): TimeTrackUserRef
    {
        if ($user instanceof HostUser) {
            // Since 1.23.0 (`user_mapping: id`): a stored owner is your user's class and id (no object of yours).
            return new TimeTrackUserRef($user->getUserClass(), $user->getId(), '', [], $user);
        }
        $id = UserIdResolver::idOf($user);
        if ($id !== null && trim($id) === '') {
            $id = null; // a blank id (e.g. getId() returning ' ') counts as none
        }

        return $user instanceof UserInterface
            ? new TimeTrackUserRef(TimeTrackUserRefs::className($user), $id, $user->getUserIdentifier(), $user->getRoles(), $user)
            : new TimeTrackUserRef(TimeTrackUserRefs::className($user), $id, '', [], $user);
    }
}
