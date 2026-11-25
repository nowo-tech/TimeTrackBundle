<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): host port that registers device-flow **scope families** next to the bundle's own
 * `time` family (e.g. Beacon registers `chat` for ChatKit's client API). Every service implementing it is tagged
 * `nowo_time_track.device_scope_family_provider` by autoconfiguration; the bundle also registers one from
 * `client_api.device_flow.scope_families`.
 *
 * Called on every device request, approval page and family authentication (nothing is cached between requests): keep it
 * cheap, side-effect free and constant for a deployment. A family that no provider returns is refused (deny by default),
 * so removing a family stops its tokens at their next use.
 */
interface DeviceScopeFamilyProviderInterface
{
    /**
     * The families this provider registers. A key may be registered once (never `time`), and a scope value belongs to
     * one family only.
     *
     * @return iterable<DeviceScopeFamily>
     */
    public function scopeFamilies(): iterable;
}
