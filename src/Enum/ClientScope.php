<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Enum;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;

use function array_filter;
use function array_map;
use function array_values;
use function get_debug_type;
use function is_string;
use function sprintf;

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
     * Known scopes of a stored list (token, device request): unknown values are dropped, duplicates removed, order of
     * {@see self::cases()}. Only an absent list (null, a token issued before 1.9) means every scope: a list that holds
     * no known scope grants none, never every scope.
     *
     * @param iterable<mixed>|null $values Scope strings (or enum cases); null = every scope
     *
     * @return list<self>
     */
    public static function normalize(?iterable $values): array
    {
        if ($values === null) {
            return self::all();
        }

        $wanted = [];
        foreach ($values as $value) {
            $scope = $value instanceof self ? $value : (is_string($value) ? self::tryFrom($value) : null);
            if ($scope instanceof self) {
                $wanted[$scope->value] = true;
            }
        }

        return array_values(array_filter(self::cases(), static fn (self $scope): bool => isset($wanted[$scope->value])));
    }

    /**
     * Scopes of a requested list (device flow, host {@see ClientAuthResult}): null means
     * every scope; an empty list, or any unknown value, is rejected so a misspelled or future scope never widens a grant.
     *
     * @param array<mixed>|null $values Scope strings (or enum cases); null = every scope
     *
     * @throws InvalidArgumentException When the list is empty or holds an unknown value
     *
     * @return list<self>
     */
    public static function fromRequested(?array $values): array
    {
        if ($values === null) {
            return self::all();
        }

        foreach ($values as $value) {
            if (!$value instanceof self && (!is_string($value) || self::tryFrom($value) === null)) {
                throw new InvalidArgumentException(sprintf('Unknown client API scope "%s".', is_string($value) ? $value : get_debug_type($value)));
            }
        }

        if ($values === []) {
            throw new InvalidArgumentException('The client API scope list is empty (omit it to grant every scope).');
        }

        return self::normalize($values);
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
