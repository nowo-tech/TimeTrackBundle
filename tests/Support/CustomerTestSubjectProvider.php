<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

/**
 * Since 2.1.0: test provider of the `customer` subject type, which may sit next to a task (customer-like type).
 */
final class CustomerTestSubjectProvider extends AllocationTestSubjectProvider
{
    /**
     * The subject type.
     */
    public static function subjectType(): string
    {
        return 'customer';
    }

    /**
     * A customer may sit next to a task.
     */
    public static function allowsTask(): bool
    {
        return true;
    }
}
