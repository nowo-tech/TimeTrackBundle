<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

use function base64_encode;
use function hash;
use function preg_match;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Since 1.19: a PKCE pair (RFC 7636, `S256`) for one authorization flow. Keep the verifier server side (session) until
 * the callback; send only the challenge to {@see CalendarOAuthClientInterface::authorizationUrl()}. Debug output is
 * redacted and serialization throws (store `$verifier` itself, not this object).
 */
final readonly class CalendarPkce
{
    /** RFC 7636 verifier: 43-128 unreserved characters. */
    public const VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}$/D';

    /**
     * @param string $verifier The code verifier
     * @param string $challenge `BASE64URL(SHA256(verifier))`
     */
    private function __construct(
        #[SensitiveParameter]
        public string $verifier,
        public string $challenge,
    ) {
    }

    /**
     * A new random pair (64 random bytes, 86 characters).
     */
    public static function create(): self
    {
        return self::fromVerifier(self::base64Url(random_bytes(64)));
    }

    /**
     * The pair of a stored verifier.
     *
     * @throws InvalidArgumentException When the verifier is not 43-128 unreserved characters
     */
    public static function fromVerifier(#[SensitiveParameter] string $verifier): self
    {
        if (preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
            throw new InvalidArgumentException('A PKCE verifier is 43-128 unreserved characters.');
        }

        return new self($verifier, self::base64Url(hash('sha256', $verifier, true)));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['verifier' => '[redacted]', 'challenge' => $this->challenge];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A PKCE pair cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A PKCE pair cannot be unserialized.');
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
