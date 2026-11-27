<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Since 2.4.0 (Beacon spec 262 block B): the client modules a person may use, from the host
 * {@see ClientCapabilitiesProviderInterface} (`client_api.capabilities.provider`) over the registered scope families
 * ({@see DeviceScopeFamilyRegistry}). One module = one scope family.
 *
 * Without a provider nothing is enforced and every registered family is usable (the 2.3 behaviour). With one, a family
 * the provider refuses (or a provider exception: fail closed, logged) is not granted by the device flow or a password
 * login and its tokens answer `403 module_forbidden` on every request.
 *
 * Stateless: the provider and the registry are asked on every call, nothing is kept between requests (FrankenPHP worker
 * mode, kernel reset false).
 *
 * @internal
 */
final readonly class ClientCapabilitiesResolver
{
    /** Error code of a request whose module (scope family) the person may no longer use. */
    public const MODULE_FORBIDDEN = 'module_forbidden';

    /**
     * Modules always present in `GET me/capabilities` (false while not registered), so clients can read them without a
     * key check. Other registered families are listed too.
     *
     * @var list<string>
     */
    public const MODULES = [DeviceScopeFamily::TIME, 'chat', 'tasks'];

    private LoggerInterface $logger;

    /**
     * @param ClientCapabilitiesProviderInterface|null $provider `client_api.capabilities.provider` (null = nothing enforced)
     * @param DeviceScopeFamilyRegistry $families The registered families
     */
    public function __construct(
        private ?ClientCapabilitiesProviderInterface $provider = null,
        private DeviceScopeFamilyRegistry $families = new DeviceScopeFamilyRegistry(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Whether a host provider is configured (the grants and the per-request module check are enforced).
     */
    public function isEnforced(): bool
    {
        return $this->provider instanceof ClientCapabilitiesProviderInterface;
    }

    /**
     * Whether the family is registered (`time` always is).
     */
    public function isRegistered(string $scopeFamily): bool
    {
        return $this->families->has($scopeFamily);
    }

    /**
     * Whether the person may use the module now: always true without a provider; with one, the provider's answer (false
     * on an exception, logged).
     *
     * @param object $user The person (security user, or a framework-free reference)
     * @param string|null $context Canonical context key (null = none named)
     */
    public function canUse(object $user, string $scopeFamily, ?string $context): bool
    {
        if (!$this->provider instanceof ClientCapabilitiesProviderInterface) {
            return true;
        }

        try {
            return $this->provider->canUse(TimeTrackUserRefs::fromUser($user), $scopeFamily, $context);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack client capabilities provider failed.', ['bundle' => 'time_track', 'action' => 'client_capabilities', 'family' => $scopeFamily, 'exception' => $exception::class]);

            return false;
        }
    }

    /**
     * The modules of the person in the context: `modules` (family key => usable; {@see self::MODULES} always present,
     * false while not registered) and `grantableScopes` (the scope values of each usable family, which a device may ask
     * for in the device flow).
     *
     * @param object $user The person
     * @param string|null $context Canonical context key (null = none named)
     *
     * @return array{modules: array<string, bool>, grantableScopes: array<string, list<string>>}
     */
    public function capabilitiesOf(object $user, ?string $context): array
    {
        $modules = [];
        foreach (self::MODULES as $module) {
            $modules[$module] = false;
        }
        $grantable = [];
        foreach ($this->families->all() as $key => $family) {
            $modules[$key] = $this->canUse($user, $key, $context);
            if ($modules[$key]) {
                $grantable[$key] = $family->scopeValues();
            }
        }

        return ['modules' => $modules, 'grantableScopes' => $grantable];
    }
}
