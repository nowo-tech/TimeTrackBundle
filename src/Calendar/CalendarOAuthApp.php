<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use JsonSerializable;
use LogicException;
use SensitiveParameter;
use Stringable;

use function is_string;
use function sprintf;
use function trim;

/**
 * Since 1.20 (Beacon request 2026-11-11): the OAuth app registration of one calendar provider — client id, client
 * secret and, for Microsoft, the tenant — as a {@see CalendarOAuthAppProviderInterface} returns it at runtime.
 *
 * The secret is read through {@see self::revealClientSecret()} only. `__toString()`, debug output (`var_dump()`,
 * `print_r()`, Symfony VarDumper and the profiler's dump panel), `json_encode()`, `serialize()`, `var_export()`, an
 * `(array)` cast and `get_object_vars()` see `[redacted]` or an empty closure; unserializing throws (a redacted copy is
 * no app). This guards against accidental output, not deliberate access: reflection on the internal wrapper, or a cast
 * of that wrapper itself, still reaches the secret (audit ML-I1). The token request bodies are another channel: in
 * debug, the profiler's HTTP Client panel records them in plain text (audit ML-L1, see `calendar.http_client`).
 * Build one per call from the host store; never cache it in a long-lived service.
 */
final readonly class CalendarOAuthApp implements JsonSerializable, Stringable
{
    private SensitiveValue $clientSecret;

    /**
     * @param string $clientId OAuth client id (empty = not configured)
     * @param string $clientSecret OAuth client secret (empty = not configured)
     * @param string|null $tenant Microsoft only: `common`, `organizations`, `consumers` or a tenant id / domain; null
     *                            falls back to `calendar.oauth.microsoft.tenant`. Ignored for Google.
     */
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        string $clientSecret,
        public ?string $tenant = null,
    ) {
        $this->clientSecret = new SensitiveValue($clientSecret);
    }

    /**
     * The client secret, for the token request only. Never log, print or store it.
     */
    public function revealClientSecret(): string
    {
        $secret = $this->clientSecret->reveal();

        return is_string($secret) ? $secret : '';
    }

    /**
     * Whether client id and secret are both set (not blank).
     */
    public function isComplete(): bool
    {
        return trim($this->clientId) !== '' && trim($this->revealClientSecret()) !== '';
    }

    /**
     * Redacted description.
     */
    public function __toString(): string
    {
        return sprintf('CalendarOAuthApp(clientId: %s, clientSecret: %s, tenant: %s)', $this->clientId, SensitiveValue::REDACTED, $this->tenant ?? 'null');
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
     * Redacted.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'clientSecret' => SensitiveValue::REDACTED, 'tenant' => $this->tenant];
    }

    /**
     * Redacted: the serialized form never holds the secret.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * A redacted copy is no app: refused.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A calendar OAuth app cannot be unserialized.');
    }
}
