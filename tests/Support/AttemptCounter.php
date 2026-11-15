<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

/**
 * 1.27.0: counts the attempts of a replayed unit of work.
 */
final class AttemptCounter
{
    public int $count = 0;
}
