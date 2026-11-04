<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

/**
 * What runs after an idle span reassigned to another task (`then` of `POST timer/discard-idle`, since 1.10).
 */
enum IdleFollowUp: string
{
    /** A new timer on the original task from the end of the span (like `split`). */
    case Continue = 'continue';

    /** A new timer on the task the span was reassigned to, from the end of the span. */
    case Switch = 'switch';

    /** No timer runs afterwards. */
    case Stop = 'stop';
}
