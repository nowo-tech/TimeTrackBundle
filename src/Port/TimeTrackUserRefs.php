<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Port;

use LogicException;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function sprintf;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * Converts between the Symfony users the TimeTrack services work on and the framework-free {@see TimeTrackUserRef} of
 * the 1.23 port twins and event accessors (REQ-ARCH-001 rule 3). Stateless.
 *
 * @internal
 */
final class TimeTrackUserRefs
{
    /** Namespace marker of Doctrine's generated proxy classes (`Proxies\__CG__\App\Entity\User`). */
    private const PROXY_MARKER = '\\__CG__\\';

    /**
     * The reference of a user object: its class (no Doctrine proxy), `UserIdResolver::idOf()` (null when none or blank),
     * its identifier and role names when it is a Symfony user ('' and none otherwise), and the object itself (hidden).
     */
    public static function fromUser(object $user): TimeTrackUserRef
    {
        $id = UserIdResolver::idOf($user);
        if ($id !== null && trim($id) === '') {
            $id = null; // a blank id (e.g. getId() returning ' ') counts as none
        }

        return $user instanceof UserInterface
            ? new TimeTrackUserRef(self::className($user), $id, $user->getUserIdentifier(), $user->getRoles(), $user)
            : new TimeTrackUserRef(self::className($user), $id, '', [], $user);
    }

    /**
     * The Symfony user a reference must carry where the bundle needs the user object (to store it or ask a deprecated
     * port about it).
     *
     * @param string $port the port named in the error
     *
     * @throws LogicException when the reference carries no Symfony user object
     */
    public static function securityUser(TimeTrackUserRef $ref, string $port): UserInterface
    {
        $user = $ref->user();
        if (!$user instanceof UserInterface) {
            throw new LogicException(sprintf('%s returned a user reference without a Symfony security user object (TimeTrackUserRef::of() with the object your user provider returns); TimeTrack needs it to store and check the user.', $port));
        }

        return $user;
    }

    /**
     * The user's class without a Doctrine proxy wrapper.
     */
    public static function className(object $user): string
    {
        $class = $user::class;
        $pos   = strrpos($class, self::PROXY_MARKER);

        return $pos === false ? $class : substr($class, $pos + strlen(self::PROXY_MARKER));
    }
}
