<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use LogicException;
use SensitiveParameter;

/**
 * Since 1.14: an OAuth access token for one sync, built by the host ({@see CalendarCredentialProviderInterface}) from
 * its encrypted store (contract §6.1, Q-B9). The bundle never stores, serializes, logs or dispatches it:
 * {@see self::reveal()} is the only way to read the secret (for the adapter's `Authorization` header), debug output is
 * redacted and serialization throws (so it cannot reach a Messenger message, a cache or a session).
 */
final readonly class CalendarCredential
{
    /**
     * @param string $accessToken Bearer token with a read-only calendar scope
     */
    public function __construct(
        #[SensitiveParameter]
        private string $accessToken,
    ) {
    }

    /** The token, for the adapter's Authorization header only. */
    public function reveal(): string
    {
        return $this->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A calendar credential cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A calendar credential cannot be unserialized.');
    }
}
