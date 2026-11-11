<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use DateTimeImmutable;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

use function is_string;

/**
 * Since 1.19 (Beacon spec 210 FR-002): tokens a provider returned to a {@see CalendarOAuthClientInterface}. Lives only
 * in memory on the way to the host's encrypted store. The tokens are read through {@see self::accessToken()} /
 * {@see self::refreshToken()} only: debug output, `json_encode()`, casts, `var_export()`, Serializer and log
 * normalisers see `[redacted]` (audit LZ-L1), and serialization throws.
 */
final readonly class CalendarTokens implements JsonSerializable
{
    private SensitiveValue $access;

    private SensitiveValue $refresh;

    /**
     * @param string $accessToken Bearer token
     * @param string|null $refreshToken Refresh token (null when the provider sent none, e.g. on a Google refresh)
     * @param DateTimeImmutable|null $expiresAt When the access token expires, one minute early (null when unknown)
     */
    public function __construct(
        #[SensitiveParameter]
        string $accessToken,
        #[SensitiveParameter]
        ?string $refreshToken,
        public ?DateTimeImmutable $expiresAt,
    ) {
        $this->access  = new SensitiveValue($accessToken);
        $this->refresh = new SensitiveValue($refreshToken);
    }

    /**
     * The access token, for the encrypted store.
     */
    public function accessToken(): string
    {
        $token = $this->access->reveal();

        return is_string($token) ? $token : '';
    }

    /**
     * The refresh token, for the encrypted store (null when none was sent).
     */
    public function refreshToken(): ?string
    {
        $token = $this->refresh->reveal();

        return is_string($token) ? $token : null;
    }

    /**
     * The access token as the credential the readers take.
     */
    public function credential(): CalendarCredential
    {
        return new CalendarCredential($this->accessToken());
    }

    /**
     * Redacted.
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
        return ['accessToken' => SensitiveValue::REDACTED, 'refreshToken' => SensitiveValue::REDACTED, 'expiresAt' => $this->expiresAt];
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
