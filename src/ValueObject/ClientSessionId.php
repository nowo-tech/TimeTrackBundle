<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\ValueObject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Stringable;

/**
 * Since 1.34.0: the id of one client session (a {@see ClientToken} row, `ClientToken::getId()`):
 * a UUID, lower-cased. Built with {@see self::fromString()} (throws on anything else) or {@see self::tryFromString()}
 * (null on anything else). Immutable; never stored by the bundle.
 */
final readonly class ClientSessionId implements Stringable
{
    private function __construct(private string $value)
    {
    }

    /**
     * Wraps a session id (lower-cased).
     *
     * @throws InvalidArgumentException when the value is not a UUID
     */
    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value)->toString());
    }

    /**
     * Wraps a session id, or null when the value is not a UUID (an id that cannot name any session).
     */
    public static function tryFromString(string $value): ?self
    {
        try {
            return self::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Canonical string form.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Canonical string form.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
