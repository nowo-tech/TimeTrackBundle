<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use LogicException;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function sprintf;

/**
 * Since 2.2.0 (Beacon Desktop BD-2, design §4.3): authenticates a device-flow token for **another bundle's** client API
 * (e.g. ChatKit's `/api/chat/v1`), so the host can implement that bundle's principal port (ChatKit's
 * `ChatClientPrincipalResolverInterface`) over TimeTrackBundle's tokens without copying any rule:
 *
 * - the same checks, in the same order, as the time API ({@see ClientApiGuard::check()}): per-IP limit, token,
 *   account status, credential state, per-token limit, then **the token's scope family must be the API's** (a `time`
 *   token never opens the chat API, and the time API never accepts a `chat` token), the scope, and the tenant context
 *   header through the host {@see ClientContextPolicyInterface}, re-checked on every request;
 * - every refusal is audited ({@see ClientApiAuditor}) with the family, like the time API's.
 *
 * Host-agnostic: no HTTP types in or out (the host passes the header values and maps {@see ClientFamilyAuthentication}).
 * Public service. Stateless: the principal is resolved per call and nothing is kept between requests (FrankenPHP
 * worker mode, kernel reset false).
 */
final readonly class ClientFamilyTokenAuthenticator
{
    /**
     * Receives the shared checks, the families, the token store and the auditor.
     */
    public function __construct(
        private ClientApiGuard $guard,
        private DeviceScopeFamilyRegistry $families,
        private ClientAuthService $authService,
        private ClientApiAuditor $auditor,
        private ClientContextResolver $contexts = new ClientContextResolver(),
    ) {
    }

    /**
     * Authenticates a request of the family's API.
     *
     * @param string $scopeFamily The API's scope family (registered, never `time`: the time API has its own guard)
     * @param string|null $authorizationHeader The request's `Authorization` header (`Bearer <token>`; null when absent)
     * @param string|null $requiredScope Scope of that family the route needs (null = any token of the family)
     * @param string|null $clientIp Client IP (its /24 or /64 is rate limited and audited)
     * @param string|null $contextHeader Value of the context header ({@see self::contextHeaderName()}; null when absent)
     *
     * @throws LogicException When the family is not registered or the scope is not one of its scopes (a wiring error)
     */
    public function authenticate(string $scopeFamily, ?string $authorizationHeader, ?string $requiredScope = null, ?string $clientIp = null, ?string $contextHeader = null): ClientFamilyAuthentication
    {
        $this->assertFamily($scopeFamily, $requiredScope);

        $check = $this->guard->check(ClientApiGuard::bearerFromHeader($authorizationHeader), $clientIp, $scopeFamily, $requiredScope, $contextHeader);
        if (!$check->token instanceof ClientToken || !$check->user instanceof UserInterface) {
            return ClientFamilyAuthentication::refused((string) $check->error, $check->retryAfter, $check->requiredScope);
        }

        $token = $check->token;

        return ClientFamilyAuthentication::authenticated(new ClientFamilyPrincipal(
            UserIdResolver::getId($check->user),
            $check->user,
            $token->getScopeFamily(),
            $token->getScopeValues(),
            $token->getId(),
            $token->getCredentialId(),
            $token->getClientType(),
            $token->getDeviceKey(),
            $check->contextKey,
        ));
    }

    /**
     * Revokes the presented token when it belongs to the family (the family API's `logout`); audited like the time
     * API's logout. Returns whether a token was revoked (a token of another family is left alone).
     *
     * @throws LogicException When the family is not registered
     */
    public function revoke(string $scopeFamily, ?string $authorizationHeader, ?string $clientIp = null): bool
    {
        $this->assertFamily($scopeFamily, null);
        $plain   = ClientApiGuard::bearerFromHeader($authorizationHeader);
        $revoked = $plain !== null ? $this->authService->logout($plain, $scopeFamily) : null;
        if (!$revoked instanceof ClientToken) {
            return false;
        }
        $this->auditor->record(ClientApiAuditAction::TokenRevoked, $revoked->getUser(), $revoked->getCredentialId(), $revoked->getClientType(), $clientIp, null, $scopeFamily);

        return true;
    }

    /**
     * The contexts the token user may name (`GET me.contexts` of the family API; null when no context policy is set).
     *
     * @return list<array{key: string, label: string}>|null
     */
    public function contextsOf(ClientFamilyPrincipal $principal): ?array
    {
        return $this->contexts->contextsOf($principal->user, $principal->scopeFamily);
    }

    /**
     * Name of the context header (`client_api.context.header`), so the host reads the same header as the time API.
     */
    public function contextHeaderName(): string
    {
        return $this->contexts->headerName();
    }

    private function assertFamily(string $scopeFamily, ?string $requiredScope): void
    {
        $family = $this->families->get($scopeFamily);
        if ($scopeFamily === DeviceScopeFamily::TIME || !$family instanceof DeviceScopeFamily) {
            throw new LogicException(sprintf('nowo_time_track: "%s" is not a registered device scope family of another API (register it with a DeviceScopeFamilyProviderInterface or client_api.device_flow.scope_families; the time API authenticates its own requests).', $scopeFamily));
        }
        if ($requiredScope !== null && !$family->has($requiredScope)) {
            throw new LogicException(sprintf('nowo_time_track: the scope "%s" is not a scope of the "%s" device scope family.', $requiredScope, $scopeFamily));
        }
    }
}
