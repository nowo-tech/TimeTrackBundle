<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 2.2.0 (Beacon Desktop, R4 / Q-BD-7): host port of the **explicit tenant context** of client API requests
 * (`client_api.context.policy`). A desktop client names its context on every request in the context header
 * (`client_api.context.header`, Beacon: `X-Beacon-Context: personal` or `org:<uuid>`); the bundle asks this policy on
 * **every** request whether the token user may use it now, so a revoked membership stops at the next request.
 *
 * The policy must be **read-only**: it never changes the user's stored web context (Beacon's
 * `User::organizationContext`) or anything else. An unknown context, a context the user may not use and a context that
 * does not exist must all answer null (the client gets the same `403 context_forbidden`). Called per request: keep it
 * cheap, and keep no request, user or tenant data in the service between calls (FrankenPHP worker mode, kernel reset
 * false).
 */
interface ClientContextPolicyInterface
{
    /**
     * The canonical key of the requested context when the user may use it now, else null.
     *
     * @param TimeTrackUserRef $user Token user
     * @param string $requested Header value (1 to 128 visible ASCII characters)
     * @param string $scopeFamily Scope family of the token (`time`, or a host family such as `chat`)
     */
    public function contextFor(TimeTrackUserRef $user, string $requested, string $scopeFamily): ?string;

    /**
     * The contexts the user may use now (`GET me.contexts`), the default first.
     *
     * @return list<ClientContextOption>
     */
    public function contextsOf(TimeTrackUserRef $user, string $scopeFamily): array;
}
