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
    /** Since 1.13: `GET workday` (R-GDPR §8). Explicit grant only. */
    case WorkdayRead = 'workday:read';
    /** Since 1.13: workday actions. Explicit grant only. */
    case WorkdayWrite = 'workday:write';
    /** Since 1.13: `GET balance`. Explicit grant only. */
    case BalanceRead = 'balance:read';
    /** Since 1.13: `GET timesheets`. Explicit grant only. */
    case TimesheetRead = 'timesheet:read';
    /** Since 1.13: submit / withdraw. Explicit grant only. */
    case TimesheetWrite = 'timesheet:write';

    /**
     * Every scope.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Since 1.13: the default grant — the pre-1.13 scope set (`timer:read`, `timer:write`, `tasks:read`,
     * `entries:read`). A token without stored scopes (issued before 1.9) and a credential that names none get these:
     * the 1.13 scopes always need an explicit grant (contract Q-O14).
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::TimerRead, self::TimerWrite, self::TasksRead, self::EntriesRead];
    }

    /**
     * Known scopes of a stored list (token, device request): unknown values are dropped, duplicates removed, order of
     * {@see self::cases()}. Only an absent list (null, a token issued before 1.9) means the default scopes ({@see self::defaults()}, the pre-1.13 set since 1.13): a list that holds
     * no known scope grants none, never every scope.
     *
     * @param iterable<mixed>|null $values Scope strings (or enum cases); null = the default scopes
     *
     * @return list<self>
     */
    public static function normalize(?iterable $values): array
    {
        if ($values === null) {
            return self::defaults();
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
     * Scopes of a requested list (device flow, host {@see ClientAuthResult}): null means the
     * default scopes (since 1.13 the pre-1.13 set); an empty list, or any unknown value, is rejected so a misspelled or future scope never widens a grant.
     *
     * @param array<mixed>|null $values Scope strings (or enum cases); null = the default scopes
     *
     * @throws InvalidArgumentException When the list is empty or holds an unknown value
     *
     * @return list<self>
     */
    public static function fromRequested(?array $values): array
    {
        if ($values === null) {
            return self::defaults();
        }

        foreach ($values as $value) {
            if (!$value instanceof self && (!is_string($value) || self::tryFrom($value) === null)) {
                throw new InvalidArgumentException(sprintf('Unknown client API scope "%s".', is_string($value) ? $value : get_debug_type($value)));
            }
        }

        if ($values === []) {
            throw new InvalidArgumentException('The client API scope list is empty (omit it to grant the default scopes).');
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
