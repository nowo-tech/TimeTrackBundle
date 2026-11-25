<?php

declare(strict_types=1);

namespace App\Subject;

use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;

/**
 * TimeTrack 2.1 showcase: the activity types of the demo tenant (one retired type shows how old entries keep a label).
 * config/services.yaml aliases the interface to it.
 */
final readonly class DemoActivityTypeProvider implements ActivityTypeProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function activityTypesFor(string $scopeKey): ActivityTypeList
    {
        return ActivityTypeList::of([
            new ActivityType('development', 'Development', true, group: 'Project'),
            new ActivityType('support_call', 'Support call', true, group: 'Customer'),
            new ActivityType('internal_meeting', 'Internal meeting', false, group: 'Internal'),
            new ActivityType('travel', 'Travel', false, active: false, group: 'Internal'),
        ]);
    }
}
