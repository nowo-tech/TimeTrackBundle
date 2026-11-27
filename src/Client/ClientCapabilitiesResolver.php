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
     * @param ClientContextResolver|null $contexts The context policy, for the default context of a request that names none (null = no policy)
     */
    public function __construct(
        private ?ClientCapabilitiesProviderInterface $provider = null,
        private DeviceScopeFamilyRegistry $families = new DeviceScopeFamilyRegistry(),
        ?LoggerInterface $logger = null,
        private ?ClientContextResolver $contexts = null,
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
     * Grant time (device approval, exchange, password login): whether the person may use the module in at least one
     * context — the provider is asked with {@see ClientCapabilitiesProviderInterface::ANY_CONTEXT}.
     */
    public function canGrant(object $user, string $scopeFamily): bool
    {
        return $this->canUse($user, $scopeFamily, ClientCapabilitiesProviderInterface::ANY_CONTEXT);
    }

    /**
     * Request time: whether the person may use the module in the request's **effective** context
     * ({@see self::effectiveContext()}). With a context policy and no context it can resolve, the answer is false (fail
     * closed). Always true without a provider.
     *
     * @param string|null $contextKey The context the request named (null = none)
     */
    public function canUseInRequest(object $user, string $scopeFamily, ?string $contextKey): bool
    {
        if (!$this->isEnforced()) {
            return true;
        }
        $context = $this->effectiveContext($user, $scopeFamily, $contextKey);
        if ($context === null && $this->contexts?->isEnabled() === true) {
            return false;
        }

        return $this->canUse($user, $scopeFamily, $context);
    }

    /**
     * The context a request runs in: the one it named; without one, the context the host serves a request that names
     * none in — the policy's default (the first of {@see ClientContextPolicyInterface::contextsOf()}); null without a
     * context policy, or when the policy lists none (or fails).
     *
     * @param string|null $contextKey The context the request named (null = none)
     */
    public function effectiveContext(object $user, string $scopeFamily, ?string $contextKey): ?string
    {
        if ($contextKey !== null || $this->contexts?->isEnabled() !== true) {
            return $contextKey;
        }

        return $this->contexts->contextsOf($user, $scopeFamily)[0]['key'] ?? null;
    }

    /**
     * Audit ZDB-v-2: a context named by the request was checked by the policy for the token's family only; another
     * family's requests check it for their own family, so it is checked here the same way (refused = module false).
     */
    private function canUseInFamilyContext(object $user, string $scopeFamily, string $tokenFamily, ?string $contextKey): bool
    {
        if ($contextKey !== null && $scopeFamily !== $tokenFamily && $this->contexts?->isEnabled() === true) {
            [$contextKey, $error] = $this->contexts->resolve($user, $contextKey, $scopeFamily);
            if ($error !== null) {
                return false;
            }
        }

        return $this->canUseInRequest($user, $scopeFamily, $contextKey);
    }

    /**
     * The provider's answer as is (false on an exception, logged); always true without a provider. Prefer
     * {@see self::canGrant()} and {@see self::canUseInRequest()}, which pass the right context.
     *
     * @param object $user The person (security user, or a framework-free reference)
     * @param string|null $context Context key, {@see ClientCapabilitiesProviderInterface::ANY_CONTEXT}, or null (no context policy)
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
     * The modules of the person in the request's context, each asked as on a request of that family
     * ({@see self::canUseInRequest()}): `context` (the effective context of the token's family, {@see self::effectiveContext()}),
     * `modules` (family key => usable; {@see self::MODULES} always present, false while not registered) and
     * `grantableScopes` (the scope values of each usable family, which a device may ask for in the device flow).
     *
     * @param object $user The person
     * @param string $tokenFamily The family of the calling token
     * @param string|null $contextKey The context the request named (null = none)
     *
     * @return array{context: string|null, modules: array<string, bool>, grantableScopes: array<string, list<string>>}
     */
    public function capabilitiesOf(object $user, string $tokenFamily, ?string $contextKey): array
    {
        $modules = [];
        foreach (self::MODULES as $module) {
            $modules[$module] = false;
        }
        $grantable = [];
        foreach ($this->families->all() as $key => $family) {
            $modules[$key] = $this->canUseInFamilyContext($user, $key, $tokenFamily, $contextKey);
            if ($modules[$key]) {
                $grantable[$key] = $family->scopeValues();
            }
        }

        return ['context' => $this->effectiveContext($user, $tokenFamily, $contextKey), 'modules' => $modules, 'grantableScopes' => $grantable];
    }
}
