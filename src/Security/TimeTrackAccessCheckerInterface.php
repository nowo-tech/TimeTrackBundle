<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Security;

/**
 * Access control for TimeTrack manage UI.
 */
interface TimeTrackAccessCheckerInterface
{
    /**
     * Whether the user may open the manage UI.
     */
    public function canAccess(?object $user): bool;
}
