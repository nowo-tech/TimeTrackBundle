<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Closure;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;

/**
 * Since 2.1.0: an activity type provider that plays a concurrent request (REQ-RUNTIME-003 race tests): the hook runs
 * once, inside the next activityTypesFor() call (before the write path takes the owner's lock), then the inner provider
 * answers.
 */
final class HookedActivityTypeProvider implements ActivityTypeProviderInterface
{
    /** @var (Closure(): mixed)|null runs once on the next activityTypesFor() call */
    public ?Closure $onList = null;

    public function __construct(private readonly ActivityTypeProviderInterface $inner = new FixedActivityTypeProvider())
    {
    }

    /**
     * Runs the armed hook once, then answers like the inner provider.
     */
    public function activityTypesFor(string $scopeKey): ActivityTypeList
    {
        $hook         = $this->onList;
        $this->onList = null;
        if ($hook instanceof Closure) {
            $hook();
        }

        return $this->inner->activityTypesFor($scopeKey);
    }
}
