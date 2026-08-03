<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Security;

/**
 * Permissive checker used only when security.allow_unauthenticated is true (demo/dev).
 */
final class AllowAllTimeTrackAccessChecker implements TimeTrackAccessCheckerInterface
{
    public function canAccess(?object $user): bool
    {
        return true;
    }
}
