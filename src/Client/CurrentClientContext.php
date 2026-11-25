<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function is_string;

/**
 * Since 2.2.0 (Beacon Desktop, R4 / Q-BD-7): the tenant context the current time client API request named and the
 * host's {@see ClientContextPolicyInterface} allowed (null: no header, the feature is off, or not a client API request).
 * Host services that answer time API requests (task provider, scope policy, …) read it instead of the user's stored web
 * context. Public service; it reads a request attribute the API guard sets, so it holds no state of its own (FrankenPHP
 * worker mode, kernel reset false).
 */
final readonly class CurrentClientContext
{
    /** Request attribute holding the allowed context key of a client API request. */
    public const ATTRIBUTE = '_nowo_time_track_client_context';

    /**
     * Receives the request stack.
     */
    public function __construct(private RequestStack $requestStack)
    {
    }

    /**
     * The allowed context key of the current request, or null.
     */
    public function key(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        $key     = $request instanceof Request ? $request->attributes->get(self::ATTRIBUTE) : null;

        return is_string($key) ? $key : null;
    }
}
