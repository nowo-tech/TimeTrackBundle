<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

/**
 * Since 2.1.0: test provider of the `internal` subject type (an internal category), which never sits next to a task.
 */
final class InternalTestSubjectProvider extends AllocationTestSubjectProvider
{
    /**
     * The subject type.
     */
    public static function subjectType(): string
    {
        return 'internal';
    }

    /**
     * An internal category never sits next to a task.
     */
    public static function allowsTask(): bool
    {
        return false;
    }
}
