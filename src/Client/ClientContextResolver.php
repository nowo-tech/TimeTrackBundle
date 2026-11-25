<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Since 2.2.0 (Beacon Desktop, R4 / Q-BD-7): checks the explicit tenant context of a client API request against the
 * host {@see ClientContextPolicyInterface}, on every request. Off (no policy): the header is ignored and the context is
 * null, as before 2.2. Fails closed: a malformed header, a context the policy refuses and a policy exception all answer
 * `context_forbidden`; a missing header answers `context_required` only with `client_api.context.required`.
 *
 * Never writes anything (the user's stored web context is never touched). Stateless (FrankenPHP worker mode, kernel
 * reset false).
 *
 * @internal
 */
final readonly class ClientContextResolver
{
    /** Error code of a refused or malformed context. */
    public const FORBIDDEN = 'context_forbidden';

    /** Error code of a missing context when one is required. */
    public const REQUIRED = 'context_required';

    /** Default header name. */
    public const DEFAULT_HEADER = 'X-Client-Context';

    private LoggerInterface $logger;

    /**
     * @param ClientContextPolicyInterface|null $policy `client_api.context.policy` (null = off)
     * @param string $header `client_api.context.header`
     * @param bool $required `client_api.context.required`: refuse a request without the header
     */
    public function __construct(
        private ?ClientContextPolicyInterface $policy = null,
        private string $header = self::DEFAULT_HEADER,
        private bool $required = false,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether a context policy is configured.
     */
    public function isEnabled(): bool
    {
        return $this->policy instanceof ClientContextPolicyInterface;
    }

    /**
     * Name of the context header.
     */
    public function headerName(): string
    {
        return $this->header;
    }

    /**
     * The context of a request: `[key, null]` when allowed (key null without a header or with the feature off), or
     * `[null, error code]`.
     *
     * @param object $user Token user
     * @param string|null $requested Header value (null when absent)
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function resolve(object $user, ?string $requested, string $scopeFamily): array
    {
        if (!$this->policy instanceof ClientContextPolicyInterface) {
            return [null, null];
        }
        if ($requested === null || $requested === '') {
            return $this->required ? [null, self::REQUIRED] : [null, null];
        }
        if (!ClientContextOption::isKey($requested)) {
            return [null, self::FORBIDDEN];
        }

        try {
            $key = $this->policy->contextFor(TimeTrackUserRefs::fromUser($user), $requested, $scopeFamily);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack client context policy failed.', ['bundle' => 'time_track', 'action' => 'client_context', 'exception' => $exception::class]);

            return [null, self::FORBIDDEN];
        }

        return $key !== null && ClientContextOption::isKey($key) ? [$key, null] : [null, self::FORBIDDEN];
    }

    /**
     * The contexts the user may use (null when the feature is off; an empty list when the policy fails).
     *
     * @return list<array{key: string, label: string}>|null
     */
    public function contextsOf(object $user, string $scopeFamily): ?array
    {
        if (!$this->policy instanceof ClientContextPolicyInterface) {
            return null;
        }

        try {
            $options = $this->policy->contextsOf(TimeTrackUserRefs::fromUser($user), $scopeFamily);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack client context policy failed.', ['bundle' => 'time_track', 'action' => 'client_context', 'exception' => $exception::class]);

            return [];
        }

        $contexts = [];
        foreach ($options as $option) {
            $contexts[] = $option->toArray();
        }

        return $contexts;
    }
}
