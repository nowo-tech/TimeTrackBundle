<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\UserRef;

use Nowo\TimeTrackBundle\UserRef\Entity\HostUser;

/**
 * Values of `nowo_time_track.user_mapping` (1.23.0, REQ-ARCH-001 rule 2): how the bundle rows store their user.
 */
final class UserMapping
{
    /** Doctrine relations to `user_class` (foreign keys to your users table): the 1.22 behaviour and the default. */
    public const RELATION = 'relation';

    /** The user's class and id in a bundle-owned row ({@see HostUser}); no relation to your users table. */
    public const ID = 'id';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::RELATION, self::ID];
    }
}
