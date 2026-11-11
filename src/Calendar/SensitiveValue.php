<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Calendar;

use Closure;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * Since 1.19 (audit LZ-L1): holds a secret or personal value so that no generic channel prints it. The value lives in a
 * closure, so `json_encode()` (this class is {@see JsonSerializable}), `(array)` casts and `get_object_vars()` of the
 * objects that hold it, `var_export()`, `var_dump()` / `print_r()` / VarDumper ({@see self::__debugInfo()}), Symfony
 * Serializer (no property, no getter) and Monolog's normaliser (JSON) all see `[redacted]` or an empty closure;
 * `serialize()` throws. Only {@see self::reveal()} returns it. Internal: never hand this wrapper itself out (a cast of
 * the wrapper alone exposes its closure to `print_r()`).
 *
 * @internal
 */
final readonly class SensitiveValue implements JsonSerializable
{
    /** What every generic channel prints instead of the value. */
    public const REDACTED = '[redacted]';

    private Closure $value;

    /**
     * Wraps the value.
     */
    public function __construct(#[SensitiveParameter] mixed $value)
    {
        $this->value = static fn (): mixed => $value;
    }

    /**
     * The wrapped value.
     */
    public function reveal(): mixed
    {
        return ($this->value)();
    }

    /**
     * Always {@see self::REDACTED}.
     */
    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A sensitive value cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A sensitive value cannot be unserialized.');
    }
}
