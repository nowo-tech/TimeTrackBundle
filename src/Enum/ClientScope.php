<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

use function array_filter;
use function array_map;
use function array_values;
use function is_string;

/**
 * Fixed scope set of the client API session tokens (contract v0.2 §1). `entries:write` is not part of 1.9.
 */
enum ClientScope: string
{
    case TimerRead   = 'timer:read';
    case TimerWrite  = 'timer:write';
    case TasksRead   = 'tasks:read';
    case EntriesRead = 'entries:read';

    /**
     * Every scope (the default grant of a credential and of a 1.8 token without stored scopes).
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Known scope values of a list; unknown values are dropped, duplicates removed, order of {@see self::cases()}.
     * An empty input means every scope.
     *
     * @param iterable<mixed> $values Scope strings (or enum cases)
     *
     * @return list<self>
     */
    public static function normalize(iterable $values): array
    {
        $wanted = [];
        $empty  = true;
        foreach ($values as $value) {
            $empty = false;
            $scope = $value instanceof self ? $value : (is_string($value) ? self::tryFrom($value) : null);
            if ($scope instanceof self) {
                $wanted[$scope->value] = true;
            }
        }

        if ($empty) {
            return self::all();
        }

        return array_values(array_filter(self::cases(), static fn (self $scope): bool => isset($wanted[$scope->value])));
    }

    /**
     * Scope values of a list of scopes.
     *
     * @param list<self> $scopes
     *
     * @return list<string>
     */
    public static function values(array $scopes): array
    {
        return array_map(static fn (self $scope): string => $scope->value, $scopes);
    }
}
