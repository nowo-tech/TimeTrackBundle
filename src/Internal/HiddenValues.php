<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Internal;

use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use WeakMap;

/**
 * Keeps the host user object of a {@see TimeTrackUserRef} (1.23.0) outside its properties, so no dump of the VO
 * (`dump()`, `var_dump()`, `var_export()`, `print_r()`, `(array)` cast, `json_encode()`, `serialize()`) shows the user
 * (password hash, personal fields). A `clone` or an unserialized copy carries none.
 *
 * Keyed by the VO in a {@see WeakMap}: an entry disappears with its VO, so nothing outlives the request in a FrankenPHP
 * worker with kernel reset false (no reset needed, REQ-RUNTIME-002).
 *
 * @internal
 */
final class HiddenValues
{
    /** @var WeakMap<object, array<string, mixed>>|null */
    private static ?WeakMap $values = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /**
     * Stores the hidden values of a VO.
     *
     * @param array<string, mixed> $values name => value
     */
    public static function put(object $owner, array $values): void
    {
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values[$owner] = $values; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * One value, or null when it is absent (or after `clone` / `unserialize()`).
     */
    public static function get(object $owner, string $name): mixed
    {
        return self::$values[$owner][$name] ?? null;
    }
}
