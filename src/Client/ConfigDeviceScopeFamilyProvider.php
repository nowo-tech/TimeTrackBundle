<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

/**
 * Since 2.2.0: the scope families of `client_api.device_flow.scope_families` (key => {label, scopes: {value: label}}).
 * The value objects are built per call (stateless, FrankenPHP worker safe).
 */
final readonly class ConfigDeviceScopeFamilyProvider implements DeviceScopeFamilyProviderInterface
{
    /**
     * @param array<string, array{label: string, scopes: array<string, string>}> $families
     */
    public function __construct(private array $families = [])
    {
    }

    /**
     * The configured families.
     *
     * @return list<DeviceScopeFamily>
     */
    public function scopeFamilies(): iterable
    {
        $families = [];
        foreach ($this->families as $key => $family) {
            $families[] = new DeviceScopeFamily($key, $family['label'], $family['scopes']);
        }

        return $families;
    }
}
