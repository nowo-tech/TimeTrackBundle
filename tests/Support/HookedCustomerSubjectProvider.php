<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Closure;
use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;

/**
 * Since 2.1.0: a `customer` subject provider that plays a concurrent request (REQ-RUNTIME-003 race tests): the hook runs
 * once, inside the next canLog() call, i.e. after the write path loaded its row and before it takes the owner's lock.
 */
final class HookedCustomerSubjectProvider extends AllocationTestSubjectProvider
{
    /** @var (Closure(): mixed)|null runs once on the next canLog() call */
    public ?Closure $onCanLog = null;

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

    /**
     * Runs the armed hook once, then answers like the base provider.
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        $hook           = $this->onCanLog;
        $this->onCanLog = null;
        if ($hook instanceof Closure) {
            $hook();
        }

        return parent::canLog($context, $subject);
    }
}
