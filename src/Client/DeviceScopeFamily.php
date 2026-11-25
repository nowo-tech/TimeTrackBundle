<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Enum\ClientScope;

use function array_keys;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): one **scope family** of the device flow, the set of scopes one client session token
 * may carry. TimeTrackBundle owns the `time` family ({@see self::TIME}, the {@see ClientScope}
 * cases); hosts register more (e.g. `chat` with `chat:read`, `chat:write`) through a
 * {@see DeviceScopeFamilyProviderInterface}. A token belongs to exactly one family: a `chat` token never opens the time
 * API and a `time` token never opens another family's API (deny by default).
 *
 * Framework-free value object, validated on construction.
 */
final readonly class DeviceScopeFamily
{
    /** The family TimeTrackBundle owns (the time client API); the default of every request that names none. */
    public const TIME = 'time';

    /** Family key: lower-case letter, then up to 31 lower-case letters, digits or `_`. */
    public const KEY_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /** Scope value: `<resource>:<action>`, lower-case (e.g. `chat:read`), at most 96 characters. */
    public const SCOPE_PATTERN = '/\A[a-z][a-z0-9_-]{0,31}:[a-z][a-z0-9_.-]{0,63}\z/';

    /** Longest label of a family or a scope. */
    public const LABEL_MAX_LENGTH = 200;

    /**
     * @param string $key Family key ({@see self::KEY_PATTERN})
     * @param string $label Name shown on the approval page (e.g. "Chat")
     * @param array<string, string> $scopes Scope value => label shown on the approval page, at least one
     *
     * @throws InvalidArgumentException On a malformed key, label or scope
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $scopes,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid device scope family key "%s" (expected %s).', $key, self::KEY_PATTERN));
        }
        self::assertLabel($label, 'family "' . $key . '"');
        if ($scopes === []) {
            throw new InvalidArgumentException(sprintf('The device scope family "%s" has no scope.', $key));
        }
        foreach ($scopes as $scope => $scopeLabel) {
            // @phpstan-ignore function.alreadyNarrowedType (array keys of digits become integers)
            if (!is_string($scope) || preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid scope "%s" in the device scope family "%s" (expected %s).', (string) $scope, $key, self::SCOPE_PATTERN));
            }
            self::assertLabel($scopeLabel, 'scope "' . $scope . '"');
        }
    }

    /**
     * Whether the scope belongs to this family.
     */
    public function has(string $scope): bool
    {
        return isset($this->scopes[$scope]);
    }

    /**
     * The scope values, in declaration order.
     *
     * @return list<string>
     */
    public function scopeValues(): array
    {
        return array_keys($this->scopes);
    }

    /**
     * Label of a scope of the family (the value itself when unknown).
     */
    public function labelOf(string $scope): string
    {
        return $this->scopes[$scope] ?? $scope;
    }

    /**
     * Whether a string is a well-formed family key.
     */
    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    private static function assertLabel(mixed $label, string $what): void
    {
        if (!is_string($label) || trim($label) === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1) {
            throw new InvalidArgumentException(sprintf('The label of the device scope %s must be 1 to %d characters without control characters.', $what, self::LABEL_MAX_LENGTH));
        }
    }
}
