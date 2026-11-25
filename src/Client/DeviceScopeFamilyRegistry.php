<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use InvalidArgumentException;
use LogicException;
use Nowo\TimeTrackBundle\Enum\ClientScope;

use function array_fill_keys;
use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): the device-flow scope families — the bundle's own `time` family plus those the
 * host's {@see DeviceScopeFamilyProviderInterface} services register — and the parser of a device request's family and
 * scopes. Deny by default: an unknown family or scope is refused, and a request never mixes families.
 *
 * Public service (host approval pages read family labels from it). Stateless: the providers are asked on every call and
 * nothing is kept between requests (FrankenPHP worker mode, kernel reset false).
 */
final readonly class DeviceScopeFamilyRegistry
{
    /** Label of the bundle's own family. */
    public const TIME_LABEL = 'Time tracking';

    /**
     * @param iterable<DeviceScopeFamilyProviderInterface> $providers The tagged providers
     */
    public function __construct(private iterable $providers = [])
    {
    }

    /**
     * Every family, `time` first, keyed by family key.
     *
     * @throws LogicException When a provider returns something else than a family, registers `time` or a key twice, or
     *                        a scope that already belongs to another family
     *
     * @return array<string, DeviceScopeFamily>
     */
    public function all(): array
    {
        $time     = self::timeFamily();
        $families = [DeviceScopeFamily::TIME => $time];
        $owners   = array_fill_keys($time->scopeValues(), DeviceScopeFamily::TIME);
        foreach ($this->providers as $provider) {
            foreach ($provider->scopeFamilies() as $family) {
                // @phpstan-ignore instanceof.alwaysTrue (a host provider may break its own phpdoc)
                if (!$family instanceof DeviceScopeFamily) {
                    throw new LogicException(sprintf('nowo_time_track: the device scope family provider "%s" returned "%s" instead of a %s.', $provider::class, get_debug_type($family), DeviceScopeFamily::class));
                }
                if (isset($families[$family->key])) {
                    throw new LogicException(sprintf('nowo_time_track: the device scope family "%s" is registered twice (the "%s" family belongs to TimeTrackBundle).', $family->key, DeviceScopeFamily::TIME));
                }
                foreach ($family->scopeValues() as $scope) {
                    if (isset($owners[$scope])) {
                        throw new LogicException(sprintf('nowo_time_track: the scope "%s" of the device scope family "%s" already belongs to the "%s" family.', $scope, $family->key, $owners[$scope]));
                    }
                    $owners[$scope] = $family->key;
                }
                $families[$family->key] = $family;
            }
        }

        return $families;
    }

    /**
     * A family by key (null when no provider registers it).
     */
    public function get(string $key): ?DeviceScopeFamily
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Whether a family is registered.
     */
    public function has(string $key): bool
    {
        return $this->get($key) instanceof DeviceScopeFamily;
    }

    /**
     * Label of a family (the key itself when unknown).
     */
    public function labelOf(string $key): string
    {
        return $this->get($key)->label ?? $key;
    }

    /**
     * Parses the `family` and `scopes` of a device request.
     *
     * - no family and no scopes: the time family with its default scopes ({@see ClientScope::defaults()});
     * - a scope list: every scope must be registered and all of one family (the family named, when one is named);
     * - a family without scopes: only `time` (its default scopes); every other family needs an explicit list.
     *
     * @param mixed $family Requested family key (null = derived from the scopes, else `time`)
     * @param mixed $scopes Requested scope values (null = the family's defaults)
     *
     * @throws InvalidArgumentException When the family is unknown, a scope is unknown or of another family, the list is
     *                                  empty, or the scopes of a family other than `time` are omitted
     */
    public function resolve(mixed $family, mixed $scopes): DeviceScopeRequest
    {
        if ($family !== null && (!is_string($family) || !DeviceScopeFamily::isKey($family))) {
            throw new InvalidArgumentException('family must be the key of a registered scope family.');
        }
        if ($scopes !== null && !is_array($scopes)) {
            throw new InvalidArgumentException('scopes must be a list.');
        }

        $families = $this->all();
        if ($family !== null && !isset($families[$family])) {
            throw new InvalidArgumentException(sprintf('Unknown scope family "%s".', $family));
        }
        if ($scopes === null) {
            if ($family !== null && $family !== DeviceScopeFamily::TIME) {
                throw new InvalidArgumentException(sprintf('The scope family "%s" needs an explicit scope list.', $family));
            }

            return new DeviceScopeRequest(DeviceScopeFamily::TIME, ClientScope::values(ClientScope::defaults()));
        }
        if ($scopes === []) {
            throw new InvalidArgumentException('The scope list is empty.');
        }

        $owner  = $family;
        $wanted = [];
        foreach ($scopes as $scope) {
            $scopeOwner = is_string($scope) ? $this->ownerOf($families, $scope) : null;
            if ($scopeOwner === null) {
                throw new InvalidArgumentException(sprintf('Unknown client API scope "%s".', is_string($scope) ? $scope : get_debug_type($scope)));
            }
            if ($owner !== null && $scopeOwner !== $owner) {
                throw new InvalidArgumentException('One device request may name the scopes of one scope family only.');
            }
            $owner                   = $scopeOwner;
            $wanted[(string) $scope] = true;
        }

        /** @var string $owner at least one scope was read */
        $ordered = [];
        foreach ($families[$owner]->scopeValues() as $value) {
            if (isset($wanted[$value])) {
                $ordered[] = $value;
            }
        }

        return new DeviceScopeRequest($owner, $ordered);
    }

    /**
     * The bundle's `time` family: every {@see ClientScope} case.
     */
    public static function timeFamily(): DeviceScopeFamily
    {
        $scopes = [];
        foreach (ClientScope::cases() as $scope) {
            $scopes[$scope->value] = $scope->value;
        }

        return new DeviceScopeFamily(DeviceScopeFamily::TIME, self::TIME_LABEL, $scopes);
    }

    /**
     * @param array<string, DeviceScopeFamily> $families
     */
    private function ownerOf(array $families, string $scope): ?string
    {
        foreach ($families as $key => $family) {
            if ($family->has($scope)) {
                return $key;
            }
        }

        return null;
    }
}
