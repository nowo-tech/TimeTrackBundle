<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

use function is_string;

/**
 * Since 1.14: an OAuth access token for one sync, built by the host ({@see CalendarCredentialProviderInterface}) from
 * its encrypted store (contract §6.1, Q-B9). The bundle never stores, serializes, logs or dispatches it:
 * {@see self::reveal()} is the only way to read the secret (for the adapter's `Authorization` header), debug output is
 * redacted and serialization throws (so it cannot reach a Messenger message, a cache or a session).
 */
final readonly class CalendarCredential implements JsonSerializable
{
    private SensitiveValue $accessToken;

    /**
     * @param string $accessToken Bearer token with a read-only calendar scope
     */
    public function __construct(
        #[SensitiveParameter]
        string $accessToken,
    ) {
        $this->accessToken = new SensitiveValue($accessToken);
    }

    /** The token, for the adapter's Authorization header only. */
    public function reveal(): string
    {
        $token = $this->accessToken->reveal();

        return is_string($token) ? $token : '';
    }

    /**
     * Redacted (since 1.19, audit LZ-L1: also `json_encode()`, casts and `var_export()` never show the token).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
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
