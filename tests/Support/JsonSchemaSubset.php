<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use function array_is_list;
use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * The JSON Schema keywords `docs/schemas/*.json` use (type, enum, properties, required, additionalProperties, items,
 * pattern, minLength, maxLength, minimum, maximum, maxItems), for contract tests without a validator dependency.
 * Returns the violations as JSON-pointer-like paths.
 */
final class JsonSchemaSubset
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return [$path . ': not in enum'];
        }
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $ok    = false;
            foreach ($types as $type) {
                $ok = $ok || self::isType($value, (string) $type);
            }
            if (!$ok) {
                return [$path . ': wrong type'];
            }
        }
        if (is_string($value)) {
            if (isset($schema['pattern']) && is_string($schema['pattern']) && preg_match('~' . $schema['pattern'] . '~u', $value) !== 1) {
                $errors[] = $path . ': pattern';
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = $path . ': maxLength';
            }
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $errors[] = $path . ': minLength';
            }
        }
        if (is_int($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = $path . ': minimum';
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = $path . ': maximum';
            }
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items']) && is_array($schema['items'])) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = $path . ': maxItems';
            }
            foreach ($value as $i => $item) {
                /** @var array<string, mixed> $items */
                $items  = $schema['items'];
                $errors = [...$errors, ...self::validate($item, $items, sprintf('%s[%d]', $path, $i))];
            }
        }
        if (is_array($value) && isset($schema['properties']) && is_array($schema['properties'])) {
            foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required) {
                if (!array_key_exists((string) $required, $value)) {
                    $errors[] = $path . '.' . $required . ': required';
                }
            }
            foreach ($value as $key => $item) {
                $property = $schema['properties'][$key] ?? null;
                if (!is_array($property)) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        $errors[] = $path . '.' . $key . ': additional property';
                    }
                    continue;
                }
                /** @var array<string, mixed> $property */
                $errors = [...$errors, ...self::validate($item, $property, $path . '.' . $key)];
            }
        }

        return $errors;
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null'    => $value === null,
            'array'   => is_array($value) && array_is_list($value),
            'object'  => is_array($value) && ($value === [] || !array_is_list($value)),
            default   => false,
        };
    }
}
