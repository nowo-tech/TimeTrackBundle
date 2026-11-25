<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Support\IpPrefix;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Stateless request checks of the client API (contract v0.2 §3/§4/§6), in this order: per-IP rate limit, Bearer
 * token, account status of the token user (since 1.29, {@see ClientAccountChecker}, `client_api.user_checker`),
 * per-request credential state ({@see ClientCredentialStateInterface}), per-token rate limit, scope family (since
 * 2.2.0: only a `time` token opens the time API), scope, tenant context (since 2.2.0, {@see ClientContextResolver}).
 * Never reads or writes the session. Every refusal is audited ({@see ClientApiAuditor::denied()}).
 *
 * Since 2.2.0 the same checks serve the other families' APIs through {@see self::check()} (used by
 * {@see ClientFamilyTokenAuthenticator}).
 */
final readonly class ClientApiGuard
{
    private ClientContextResolver $contexts;

    /**
     * Receives the collaborators of the checks.
     *
     * @param ClientContextResolver|null $contexts Since 2.2.0: the tenant context check (null = off)
     */
    public function __construct(
        private ClientAuthService $authService,
        private ClientResponseFactory $responseFactory,
        private ClientApiRateLimiter $rateLimiter,
        private ClientApiSettingsProviderInterface $settingsProvider,
        private ClientCredentialStateInterface $credentialState,
        private ClientApiAuditor $auditor,
        private ?ClientAccountChecker $accountChecker = null,
        ?ClientContextResolver $contexts = null,
    ) {
        $this->contexts = $contexts ?? new ClientContextResolver();
    }

    /**
     * Per-IP rate limit of an unauthenticated route (`device` = the stricter device-flow bucket); null when allowed.
     * IPv6 clients are keyed by their /64 ({@see IpPrefix::rateLimitKey()}).
     */
    public function limitIp(Request $request, bool $device = false): ?Response
    {
        $retry = $this->hitIp($request->getClientIp(), $device);

        return $retry > 0 ? $this->tooManyRequests($request, $retry, null) : null;
    }

    /**
     * Authenticates the request and checks the scope; returns the principal or the error response. Since 2.2.0 a token of
     * another scope family is refused (`403 insufficient_scope`), and with a context policy the context header is checked
     * and the allowed key set as the {@see CurrentClientContext::ATTRIBUTE} request attribute.
     *
     * @param ClientScope|null $scope Required scope (null = any valid `time` token)
     */
    public function authenticate(Request $request, ?ClientScope $scope = null): ClientApiPrincipal|Response
    {
        $check = $this->check(
            self::bearerToken($request),
            $request->getClientIp(),
            DeviceScopeFamily::TIME,
            $scope?->value,
            $this->contextHeader($request),
        );
        if ($check->token instanceof ClientToken && $check->user instanceof UserInterface) {
            $request->attributes->set(CurrentClientContext::ATTRIBUTE, $check->contextKey);

            return new ClientApiPrincipal($check->user, $check->token, $check->contextKey);
        }

        return $this->errorResponse($request, $check);
    }

    /**
     * Since 2.2.0: the checks of {@see self::authenticate()} for any scope family, without HTTP types. A refusal is
     * audited with the family; nothing is written but the token's last-used time.
     *
     * @param string|null $plainToken Bearer token (null when absent)
     * @param string $scopeFamily The family of the API (a token of another family is refused)
     * @param string|null $scope Required scope value of that family (null = any)
     * @param string|null $requestedContext Context header value (null when absent)
     *
     * @internal
     */
    public function check(?string $plainToken, ?string $clientIp, string $scopeFamily, ?string $scope = null, ?string $requestedContext = null): ClientTokenCheck
    {
        $retry = $this->hitIp($clientIp, false);
        if ($retry > 0) {
            $this->auditor->denied('rate_limited', null, $clientIp, $scopeFamily);

            return ClientTokenCheck::refused('rate_limited', $retry);
        }

        $token = $plainToken !== null ? $this->authService->findToken($plainToken) : null;
        if (!$token instanceof ClientToken) {
            if ($plainToken !== null) {
                $this->auditor->denied('invalid_token', null, $clientIp, $scopeFamily);
            }

            return ClientTokenCheck::refused('invalid_token');
        }

        /** @var UserInterface $user checked by ClientAuthService::findToken() */
        $user = $token->getUser();

        if ($this->accountChecker instanceof ClientAccountChecker && !$this->accountChecker->isActive($token, $user)) {
            $this->auditor->denied('account_inactive', $token, $clientIp);

            return ClientTokenCheck::refused('invalid_token');
        }

        // Since 1.29 (audit SO-I1): the last-used time is written only for an account that passed the check.
        $this->authService->touchIfStale($token);

        if (!$this->credentialState->isActive($token)) {
            $this->auditor->denied('credential_inactive', $token, $clientIp);

            return ClientTokenCheck::refused('invalid_token');
        }

        $settings = $this->settingsProvider->getSettings();
        $retry    = $this->rateLimiter->hit('token', $token->getTokenHash(), $settings->perTokenLimit, $settings->windowSeconds);
        if ($retry > 0) {
            $this->auditor->denied('rate_limited', $token, $clientIp);

            return ClientTokenCheck::refused('rate_limited', $retry, null, $token);
        }

        // Since 2.2.0 (Beacon Desktop R3): a token opens the API of its own scope family only.
        if ($token->getScopeFamily() !== $scopeFamily) {
            $this->auditor->denied('scope_family', $token, $clientIp);

            return ClientTokenCheck::refused('insufficient_scope', 0, $scope ?? $scopeFamily, $token);
        }

        if ($scope !== null && !$token->hasScopeValue($scope)) {
            $this->auditor->denied('insufficient_scope', $token, $clientIp);

            return ClientTokenCheck::refused('insufficient_scope', 0, $scope, $token);
        }

        [$contextKey, $contextError] = $this->contexts->resolve($user, $requestedContext, $scopeFamily);
        if ($contextError !== null) {
            $this->auditor->denied($contextError, $token, $clientIp);

            return ClientTokenCheck::refused($contextError, 0, null, $token);
        }

        return ClientTokenCheck::passed($token, $user, $contextKey);
    }

    /**
     * Since 2.2.0 (Beacon Desktop, Q-BD-6 / Q-BD-19): the device key and the owner's user id of a token a device presents
     * on `device/code` to join the new family's token to the same device row — the token must pass the same checks as on
     * any API request (valid, account active, credential active), whatever its family. Null (audited) otherwise. The
     * owner is stored on the request: only that user may approve it (audit ZBV-M1).
     *
     * @return array{deviceKey: string, ownerId: string}|null
     */
    public function deviceLinkOf(string $plainToken, ?string $clientIp): ?array
    {
        $token = $this->authService->findToken($plainToken);
        if (!$token instanceof ClientToken) {
            $this->auditor->denied('invalid_token', null, $clientIp);

            return null;
        }
        /** @var UserInterface $user checked by ClientAuthService::findToken() */
        $user = $token->getUser();
        if ($this->accountChecker instanceof ClientAccountChecker && !$this->accountChecker->isActive($token, $user)) {
            $this->auditor->denied('account_inactive', $token, $clientIp);

            return null;
        }
        if (!$this->credentialState->isActive($token)) {
            $this->auditor->denied('credential_inactive', $token, $clientIp);

            return null;
        }

        return ['deviceKey' => $token->getDeviceKey() ?? $token->getId(), 'ownerId' => UserIdResolver::getId($user)];
    }

    /**
     * Plain Bearer token of the request (null when absent or blank).
     */
    public static function bearerToken(Request $request): ?string
    {
        return self::bearerFromHeader((string) $request->headers->get('Authorization', ''));
    }

    /**
     * Since 2.2.0: plain Bearer token of an `Authorization` header value (null when absent or blank).
     */
    public static function bearerFromHeader(?string $header): ?string
    {
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    /**
     * Since 2.2.0: the value of the configured context header (null when absent).
     */
    public function contextHeader(Request $request): ?string
    {
        $value = $request->headers->get($this->contexts->headerName());

        return is_string($value) ? $value : null;
    }

    private function hitIp(?string $clientIp, bool $device): int
    {
        $settings = $this->settingsProvider->getSettings();
        $ip       = IpPrefix::rateLimitKey($clientIp);

        return $device
            ? $this->rateLimiter->hit('device_ip', $ip, $settings->deviceIpLimit, $settings->windowSeconds)
            : $this->rateLimiter->hit('ip', $ip, $settings->perIpLimit, $settings->windowSeconds);
    }

    private function errorResponse(Request $request, ClientTokenCheck $check): JsonResponse
    {
        return match ($check->error) {
            'rate_limited' => $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, [
                'Retry-After' => (string) $check->retryAfter,
            ]),
            'insufficient_scope' => $this->responseFactory->error('insufficient_scope', sprintf('The token lacks the "%s" scope.', (string) $check->requiredScope), Response::HTTP_FORBIDDEN, $request, [
                'WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', (string) $check->requiredScope),
            ]),
            ClientContextResolver::FORBIDDEN => $this->responseFactory->error(ClientContextResolver::FORBIDDEN, 'The context is unknown or not available to you.', Response::HTTP_FORBIDDEN, $request),
            ClientContextResolver::REQUIRED  => $this->responseFactory->error(ClientContextResolver::REQUIRED, sprintf('Name the context in the %s header.', $this->contexts->headerName()), Response::HTTP_BAD_REQUEST, $request),
            default                          => $this->responseFactory->error('invalid_token', 'Missing, invalid, expired or revoked token.', Response::HTTP_UNAUTHORIZED, $request, [
                'WWW-Authenticate' => 'Bearer error="invalid_token"',
            ]),
        };
    }

    private function tooManyRequests(Request $request, int $retryAfter, ?ClientToken $token): JsonResponse
    {
        $this->auditor->denied('rate_limited', $token, $request->getClientIp());

        return $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, [
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
