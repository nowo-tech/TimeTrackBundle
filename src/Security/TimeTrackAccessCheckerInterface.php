<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Security;

/**
 * Access control for TimeTrack manage UI.
 */
interface TimeTrackAccessCheckerInterface
{
    public function canAccess(?object $user): bool;
}
