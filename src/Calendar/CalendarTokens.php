<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use LogicException;
use SensitiveParameter;

/**
 * Since 1.19 (Beacon spec 210 FR-002): tokens a provider returned to a {@see CalendarOAuthClientInterface}. Lives only
 * in memory on the way to the host's encrypted store: debug output is redacted and serialization throws.
 */
final readonly class CalendarTokens
{
    /**
     * @param string $accessToken Bearer token
     * @param string|null $refreshToken Refresh token (null when the provider sent none, e.g. on a Google refresh)
     * @param DateTimeImmutable|null $expiresAt When the access token expires, one minute early (null when unknown)
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        #[SensitiveParameter]
        public ?string $refreshToken,
        public ?DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The access token as the credential the readers take.
     */
    public function credential(): CalendarCredential
    {
        return new CalendarCredential($this->accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'refreshToken' => '[redacted]', 'expiresAt' => $this->expiresAt];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Calendar tokens cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Calendar tokens cannot be unserialized.');
    }
}
