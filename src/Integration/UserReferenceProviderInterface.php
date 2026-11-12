<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.23.0: framework-free twin of {@see UserReferenceResolverInterface} (REQ-ARCH-001 rule 3). Alias this
 * interface to your service (instead of the deprecated one): the bundle asks it for the object stored in its `user`
 * associations.
 */
interface UserReferenceProviderInterface
{
    /**
     * The managed `user_class` entity, or a reference to it, that stands for this user (the ref of the security user
     * a bundle service was called with; it always carries that object, {@see TimeTrackUserRef::user()}).
     */
    public function referenceFor(TimeTrackUserRef $user): object;
}
