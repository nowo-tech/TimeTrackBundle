<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 2.4.0 (Beacon spec 262 block B, Beacon Desktop permission parity): host port that answers whether a person may
 * use a **module** of the client apps now, a module being a device-flow scope family (`time`, and the host's families
 * such as `chat` or `tasks`). Configure it with `client_api.capabilities.provider` (a service id). Answer from the same
 * permission checks as the web: a person who may not track time on the web gets `false` for `time`.
 *
 * The bundle asks it:
 *
 * - **before granting** a family: when the user approves a device request (the default page and
 *   {@see DeviceAuthorizationService::approve()}), when the device exchanges an approved
 *   request, and on a password login (`POST login`, app passwords: the `time` family). A family the provider refuses is
 *   neither granted nor shown on the approval page. `$context` is null there (the device flow and a login name no context):
 *   answer whether the person may use the module in at least one context;
 * - **on every client API request** of a token of that family (time routes and {@see ClientFamilyTokenAuthenticator}),
 *   after the token, scope and context checks: a refused family answers `403 module_forbidden` (the token stays valid, so
 *   the module works again as soon as the permission comes back). `$context` is the context the request named (null
 *   without a context policy or a header);
 * - for `GET me/capabilities`, once per registered family.
 *
 * Without a provider the bundle keeps the 2.3 behaviour: every registered family is granted and usable.
 *
 * Deny by default: answer false for a family you do not know. Must be read-only and cheap (called per request); keep no
 * request, user or tenant data in the service between calls (FrankenPHP worker mode, kernel reset false). An exception is
 * treated as false (fail closed) and logged.
 */
interface ClientCapabilitiesProviderInterface
{
    /**
     * Whether the person may use the module (scope family) now.
     *
     * @param TimeTrackUserRef $user The person (token user, or the user approving a device request or logging in)
     * @param string $scopeFamily Family key (`time`, or a registered host family)
     * @param string|null $context Canonical context key of the request (null = no context named; see the class doc)
     */
    public function canUse(TimeTrackUserRef $user, string $scopeFamily, ?string $context): bool;
}
