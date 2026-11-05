<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Support;

use function array_is_list;
use function hash;
use function is_array;
use function json_encode;
use function ksort;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

/**
 * Since 1.13: canonical JSON of the audit chains (contract §3.3, §5.8): object keys sorted recursively (byte order),
 * lists kept in order, no whitespace, slashes and Unicode unescaped. The same value always gives the same bytes, so a
 * value read back from a database that reorders JSON keys (MySQL) hashes like the value written.
 */
final class CanonicalJson
{
    /**
     * Canonical JSON text of a value (scalars, null, lists and string-keyed maps).
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * SHA-256 (hex) of `$prefix` followed by the canonical JSON of `$value`.
     */
    public static function hash(mixed $value, string $prefix = ''): string
    {
        return hash('sha256', $prefix . self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item);
        }
        if (array_is_list($normalized)) {
            return $normalized;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
