<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TimeTrackBundle\Client\ClientFamilyCredentialMinterInterface;
use Nowo\TimeTrackBundle\Client\ConfigDeviceScopeFamilyProvider;
use Nowo\TimeTrackBundle\DependencyInjection\TimeTrackExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Since 2.2.0 (audit ZBV-I1): a device flow that registers a scope family other than `time` must mint its credentials
 * through a {@see ClientFamilyCredentialMinterInterface} (`client_api.credential_issuer`), so the host stores the family
 * and the device key and can revoke each family on its own. The build fails when such a family is registered (configured
 * families, or any {@see TimeTrackExtension::SCOPE_FAMILY_PROVIDER_TAG} service) and the issuer is the bundle's opaque
 * default or a known class that does not implement the interface. A service whose class cannot be known here is left to
 * the runtime check (approving fails, nothing is minted).
 *
 * @internal
 */
final class ScopeFamilyMinterPass implements CompilerPassInterface
{
    /** Parameter: the configured `client_api.credential_issuer` service id (null = the opaque default). */
    public const ISSUER_PARAMETER = 'nowo_time_track.client_api.credential_issuer';

    /**
     * @throws LogicException When a non-time family is registered without a family-aware minter
     */
    public function process(ContainerBuilder $container): void
    {
        $parameters = $container->getParameterBag();
        if (!$parameters->has('nowo_time_track.client_api.device_flow.enabled') || $parameters->get('nowo_time_track.client_api.device_flow.enabled') !== true || !$parameters->has(self::ISSUER_PARAMETER)) {
            return;
        }

        $sources = [];
        foreach (array_keys($container->findTaggedServiceIds(TimeTrackExtension::SCOPE_FAMILY_PROVIDER_TAG)) as $id) {
            if ($id !== ConfigDeviceScopeFamilyProvider::class) {
                $sources[] = $id;
            }
        }
        $configured = $parameters->has('nowo_time_track.client_api.device_flow.scope_families') ? $parameters->get('nowo_time_track.client_api.device_flow.scope_families') : [];
        if ($configured !== []) {
            $sources[] = 'client_api.device_flow.scope_families';
        }
        if ($sources === []) {
            return;
        }

        $issuer = $parameters->get(self::ISSUER_PARAMETER);
        if (!is_string($issuer) || $issuer === '') {
            throw new LogicException(sprintf('nowo_time_track: scope families are registered (%s) but client_api.credential_issuer is not set: the default opaque issuer mints time credentials only. Configure a service implementing %s.', $sources[0], ClientFamilyCredentialMinterInterface::class));
        }
        $class = $container->hasDefinition($issuer) ? $parameters->resolveValue($container->getDefinition($issuer)->getClass() ?? $issuer) : null;
        if (is_string($class) && class_exists($class) && !is_a($class, ClientFamilyCredentialMinterInterface::class, true)) {
            throw new LogicException(sprintf('nowo_time_track: scope families are registered (%s) but client_api.credential_issuer "%s" does not implement %s.', $sources[0], $issuer, ClientFamilyCredentialMinterInterface::class));
        }
    }
}
