<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): the answer of {@see ClientFamilyTokenAuthenticator::authenticate()} — the principal,
 * or a stable error code with the HTTP status and headers the other bundle's API should answer with (no HTTP types, so
 * any framework can map it). Value object.
 *
 * | error                | status | meaning                                                                |
 * |----------------------|--------|------------------------------------------------------------------------|
 * | `invalid_token`      | 401    | missing, unknown, expired or revoked token; refused account/credential |
 * | `insufficient_scope` | 403    | a token of another scope family, or without the required scope         |
 * | `context_forbidden`  | 403    | the context header names a context the user may not use (or none)      |
 * | `context_required`   | 400    | no context header while `client_api.context.required` is on            |
 * | `rate_limited`       | 429    | per-IP or per-token limit (`retryAfter` seconds)                        |
 */
final readonly class ClientFamilyAuthentication
{
    /**
     * @param string|null $requiredScope The scope (or family) that was missing (`insufficient_scope`)
     */
    private function __construct(
        public ?ClientFamilyPrincipal $principal,
        public ?string $error,
        public int $retryAfter = 0,
        public ?string $requiredScope = null,
    ) {
    }

    /**
     * An authenticated request.
     */
    public static function authenticated(ClientFamilyPrincipal $principal): self
    {
        return new self($principal, null);
    }

    /**
     * A refused request.
     */
    public static function refused(string $error, int $retryAfter = 0, ?string $requiredScope = null): self
    {
        return new self(null, $error, $retryAfter, $requiredScope);
    }

    /**
     * Whether the request is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->principal instanceof ClientFamilyPrincipal;
    }

    /**
     * HTTP status of the refusal (200 when authenticated).
     */
    public function status(): int
    {
        return match ($this->error) {
            null                             => 200,
            'rate_limited'                   => 429,
            'insufficient_scope'             => 403,
            ClientContextResolver::FORBIDDEN => 403,
            ClientContextResolver::REQUIRED  => 400,
            default                          => 401,
        };
    }

    /**
     * Response headers of the refusal (`WWW-Authenticate`, `Retry-After`).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return match ($this->error) {
            'invalid_token'      => ['WWW-Authenticate' => 'Bearer error="invalid_token"'],
            'insufficient_scope' => ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . (string) $this->requiredScope . '"'],
            'rate_limited'       => ['Retry-After' => (string) $this->retryAfter],
            default              => [],
        };
    }
}
