<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

/**
 * Since 2.1.0 (Beacon spec 256): the activity types of each tenant. One service: alias this interface to it (the
 * `nowo_time_track.port.activity_types` slot); without one, activity types are off and a key is refused
 * (`activity_type_unknown`). The bundle validates a key on every write (it must exist and be active in the entry's scope)
 * and labels retired keys on reads. Asked at most once per request path per scope, never memoised by the bundle (worker
 * mode): cache in your own tag-aware pool if you need to. An exception is logged (class only) and read as "no types".
 */
interface ActivityTypeProviderInterface
{
    /**
     * Every activity type of the tenant, retired ones included (`active: false`).
     *
     * @param string $scopeKey The tenant scope ('' = no scope)
     */
    public function activityTypesFor(string $scopeKey): ActivityTypeList;
}
