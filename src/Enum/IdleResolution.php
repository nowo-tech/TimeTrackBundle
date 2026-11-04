<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * The user's decision about an idle span the client detected locally (`POST timer/discard-idle`).
 */
enum IdleResolution: string
{
    /** Count the idle span: nothing changes. */
    case Keep = 'keep';

    /** Drop the idle span and stop: the timer ends at the start of the idle span. */
    case Discard = 'discard';

    /** Drop the idle span and continue: an entry up to the idle start, a new timer on the same task from its end. */
    case Split = 'split';

    /**
     * Give the idle span to another task (since 1.10): an entry up to the idle start, an entry on the other task for
     * the span, then what `then` says ({@see IdleFollowUp}).
     */
    case Reassign = 'reassign';
}
