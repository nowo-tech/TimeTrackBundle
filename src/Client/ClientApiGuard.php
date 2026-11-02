<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Support\IpPrefix;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Stateless request checks of the client API (contract v0.2 §3/§4/§6), in this order: per-IP rate limit, Bearer
 * token, per-request credential state ({@see ClientCredentialStateInterface}), per-token rate limit, scope. Never
 * reads or writes the session. Every refusal is audited ({@see ClientApiAuditor::denied()}).
 */
final readonly class ClientApiGuard
{
    /**
     * Receives the collaborators of the checks.
     */
    public function __construct(
        private ClientAuthService $authService,
        private ClientResponseFactory $responseFactory,
        private ClientApiRateLimiter $rateLimiter,
        private ClientApiSettingsProviderInterface $settingsProvider,
        private ClientCredentialStateInterface $credentialState,
        private ClientApiAuditor $auditor,
    ) {
    }

    /**
     * Per-IP rate limit of an unauthenticated route (`device` = the stricter device-flow bucket); null when allowed.
     * IPv6 clients are keyed by their /64 ({@see IpPrefix::rateLimitKey()}).
     */
    public function limitIp(Request $request, bool $device = false): ?Response
    {
        $settings = $this->settingsProvider->getSettings();
        $ip       = IpPrefix::rateLimitKey($request->getClientIp());
        $retry    = $device
            ? $this->rateLimiter->hit('device_ip', $ip, $settings->deviceIpLimit, $settings->windowSeconds)
            : $this->rateLimiter->hit('ip', $ip, $settings->perIpLimit, $settings->windowSeconds);

        return $retry > 0 ? $this->tooManyRequests($request, $retry, null) : null;
    }

    /**
     * Authenticates the request and checks the scope; returns the principal or the error response.
     *
     * @param ClientScope|null $scope Required scope (null = any valid token)
     */
    public function authenticate(Request $request, ?ClientScope $scope = null): ClientApiPrincipal|Response
    {
        $limited = $this->limitIp($request);
        if ($limited instanceof Response) {
            return $limited;
        }

        $plain = self::bearerToken($request);
        $token = $plain !== null ? $this->authService->resolveToken($plain) : null;
        if (!$token instanceof ClientToken) {
            if ($plain !== null) {
                $this->auditor->denied('invalid_token', null, $request->getClientIp());
            }

            return $this->invalidToken($request);
        }

        if (!$this->credentialState->isActive($token)) {
            $this->auditor->denied('credential_inactive', $token, $request->getClientIp());

            return $this->invalidToken($request);
        }

        $settings = $this->settingsProvider->getSettings();
        $retry    = $this->rateLimiter->hit('token', $token->getTokenHash(), $settings->perTokenLimit, $settings->windowSeconds);
        if ($retry > 0) {
            return $this->tooManyRequests($request, $retry, $token);
        }

        if ($scope instanceof ClientScope && !$token->hasScope($scope)) {
            $this->auditor->denied('insufficient_scope', $token, $request->getClientIp());

            return $this->responseFactory->error('insufficient_scope', sprintf('The token lacks the "%s" scope.', $scope->value), Response::HTTP_FORBIDDEN, $request, [
                'WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', $scope->value),
            ]);
        }

        /** @var UserInterface $user checked by ClientAuthService::resolveToken() */
        $user = $token->getUser();

        return new ClientApiPrincipal($user, $token);
    }

    /**
     * Plain Bearer token of the request (null when absent or blank).
     */
    public static function bearerToken(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    private function invalidToken(Request $request): JsonResponse
    {
        return $this->responseFactory->error('invalid_token', 'Missing, invalid, expired or revoked token.', Response::HTTP_UNAUTHORIZED, $request, [
            'WWW-Authenticate' => 'Bearer error="invalid_token"',
        ]);
    }

    private function tooManyRequests(Request $request, int $retryAfter, ?ClientToken $token): JsonResponse
    {
        $this->auditor->denied('rate_limited', $token, $request->getClientIp());

        return $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, [
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
