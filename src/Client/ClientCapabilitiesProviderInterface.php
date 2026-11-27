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
 * What `$context` means (audit ZDB-L1):
 *
 * - {@see self::ANY_CONTEXT} (`''`, never a context key) — **grant time**: when the user approves a device request (the
 *   default page and {@see DeviceAuthorizationService::approve()}), when the device
 *   exchanges an approved request, and on a password login (`POST login`, app passwords: the `time` family). Answer
 *   whether the person may use the module in **at least one** context. A family you refuse is neither granted nor shown on
 *   the approval page.
 * - a context key — **request time**: on every client API request of a token of that family (time routes and
 *   {@see ClientFamilyTokenAuthenticator}), after the token, scope and context checks, and for `GET me/capabilities`
 *   (once per registered family). It is the context the request **runs in**: the one it named in the context header, or,
 *   without a header, the context the host serves such a request in — the first context of
 *   {@see ClientContextPolicyInterface::contextsOf()}, which the policy must list first: the context the host serves a
 *   header-less request in (or the host sets `client_api.context.required: true`). Answer for that context
 *   only. With a context policy that lists no context for the person (or fails), the bundle refuses without asking you.
 *   A refused family answers `403 module_forbidden`; the token stays valid, so the module works again as soon as the
 *   permission comes back.
 * - `null` — request time **without a context policy** (`client_api.context.policy` unset): the host has no tenant
 *   context; answer for the person.
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
     * The `$context` of a grant-time call (device approval, exchange, password login): "in at least one context". Never
     * a context key (keys are 1 to 128 visible ASCII characters).
     */
    public const ANY_CONTEXT = '';

    /**
     * Whether the person may use the module (scope family) now.
     *
     * @param TimeTrackUserRef $user The person (token user, or the user approving a device request or logging in)
     * @param string $scopeFamily Family key (`time`, or a registered host family)
     * @param string|null $context {@see self::ANY_CONTEXT} at grant time; the effective context key of a request; null
     *                             at request time without a context policy (see the class doc)
     */
    public function canUse(TimeTrackUserRef $user, string $scopeFamily, ?string $context): bool;
}
