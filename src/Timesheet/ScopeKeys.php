<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Timesheet;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

use function count;
use function in_array;
use function preg_match;
use function strlen;
use function trim;

/**
 * Since 1.24.1 (REQ-SEC-006, audit OA-R2 / F4): a tenant scope key (`scope_key` columns) is matched byte for byte. The
 * columns use a binary NO PAD collation on MySQL / MariaDB (set by
 * `Nowo\TimeTrackBundle\Doctrine\TimeTrackMetadataListener`), keys that a folding or PAD SPACE collation could confuse with another key are refused before they are written, and the
 * stores re-check every row a query by scope returned with `===`, so `Acme`, `àcme` or `acme ` never stand for `acme`.
 * Framework-free and stateless (FrankenPHP worker mode, kernel reset false).
 *
 * @internal
 */
final class ScopeKeys
{
    /**
     * Control, format, private-use, unassigned, surrogate, separator (except the ASCII space) and invisible filler
     * characters (also matches invalid UTF-8: preg fails).
     */
    private const INVISIBLE = '/(?! )[\p{Cc}\p{Cf}\p{Co}\p{Cn}\p{Cs}\p{Z}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}]/u';

    /**
     * Whether the key can be stored and compared exactly: at most {@see TimesheetScope::MAX_KEY_LENGTH} bytes of valid
     * UTF-8, no leading or trailing space, and no other blank (NBSP or any other separator), control, format,
     * private-use, unassigned or invisible filler character; an inner ASCII space is allowed. The empty key (`timesheets.scope_mode: single`) is usable.
     */
    public static function isUsable(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        return strlen($key) <= TimesheetScope::MAX_KEY_LENGTH && trim($key, ' ') === $key && preg_match(self::INVISIBLE, $key) === 0;
    }

    /**
     * The key itself when it is usable ({@see self::isUsable()}).
     *
     * @throws InvalidArgumentException When it is not (never trimmed or cut silently)
     */
    public static function assertUsable(string $key): string
    {
        if (!self::isUsable($key)) {
            throw new InvalidArgumentException('A TimeTrack scope key must be at most 64 bytes of valid UTF-8, with no leading or trailing space and no other blank, control, format or invisible character.');
        }

        return $key;
    }

    /**
     * Since 1.24.1 (audit OM TT-L2): a scope key a host port answered for a write, or null. A key that is not usable (a
     * legacy key with an edge space, an invisible character…) is treated as no scope with a warning, as the back-fill
     * command does, instead of failing the request: the entry is still written, without a scope.
     */
    public static function fromHost(?string $scopeKey, LoggerInterface $logger, string $action): ?string
    {
        if ($scopeKey === null || self::isUsable($scopeKey)) {
            return $scopeKey;
        }
        $logger->warning('TimeTrack ignored a scope key the scope provider returned: it has an edge space or an invisible character, or is invalid UTF-8 or too long (REQ-SEC-006).', ['bundle' => 'time_track', 'action' => $action]);

        return null;
    }

    /**
     * Since 1.24.1 (audit OM TT-L2): the host's scopes whose key is usable; the others are left out with a warning, so
     * the request goes on with the user's other scopes instead of failing.
     *
     * @param list<TimesheetScope> $scopes
     *
     * @return list<TimesheetScope>
     */
    public static function usableScopes(array $scopes, LoggerInterface $logger): array
    {
        $kept = [];
        foreach ($scopes as $scope) {
            if (self::isUsable($scope->key)) {
                $kept[] = $scope;
            }
        }
        if (count($kept) !== count($scopes)) {
            $logger->warning('TimeTrack left out {count} scope(s) the scope provider returned: their key has an edge space or an invisible character, or is invalid UTF-8 (REQ-SEC-006).', ['bundle' => 'time_track', 'action' => 'scopes_of', 'count' => count($scopes) - count($kept)]);
        }

        return $kept;
    }

    /**
     * The rows whose scope key is the key byte for byte.
     *
     * @template T
     *
     * @param list<T> $rows
     * @param callable(T): ?string $scopeOf
     *
     * @return list<T>
     */
    public static function keep(array $rows, string $scopeKey, callable $scopeOf): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if ($scopeOf($row) === $scopeKey) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * Whether the scope key is one of the keys byte for byte.
     *
     * @param list<string> $scopeKeys
     */
    public static function isOneOf(?string $scopeKey, array $scopeKeys): bool
    {
        return $scopeKey !== null && in_array($scopeKey, $scopeKeys, true);
    }
}
