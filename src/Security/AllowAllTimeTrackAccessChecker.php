<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Security;

/**
 * Permissive checker used only when security.allow_unauthenticated is true (demo/dev).
 */
final class AllowAllTimeTrackAccessChecker implements TimeTrackAccessCheckerInterface
{
    /**
     * Always true (demo only, `security.allow_unauthenticated: true`).
     */
    public function canAccess(?object $user): bool
    {
        return true;
    }
}
