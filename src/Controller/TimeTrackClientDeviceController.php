<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\DeviceScopeFamilyRegistry;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\DeviceFlowCapacityExceededException;
use Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_array;
use function is_string;
use function json_decode;
use function rawurlencode;
use function str_contains;

/**
 * Device flow endpoints of the client API (contract v0.2 §2): `POST device/code` and `POST device/token`. Stateless,
 * limited per IP (`client_api.rate_limit.device_per_ip`).
 *
 * Since 2.2.0 (contract v0.5, Beacon Desktop BD-2) `device/code` takes an optional `family` (a registered scope family,
 * default `time`); its scopes must all belong to one family (`400 invalid_scope` otherwise), and an optional Bearer token
 * the device already holds joins the new token to that device. `device/token` answers the token's `scopeFamily` too.
 */
#[Route(requirements: TimeTrackRouteLoader::REQUIREMENTS)]
final readonly class TimeTrackClientDeviceController
{
    /**
     * @param string|null $verificationUri Absolute URL of the host verification page (`client_api.device_flow.verification_uri`)
     * @param string|null $verificationRoute Route of the default page, used when no URI is configured
     */
    public function __construct(
        private DeviceAuthorizationService $deviceService,
        private ClientResponseFactory $responseFactory,
        private ClientApiGuard $guard,
        private UrlGeneratorInterface $urlGenerator,
        private ?string $verificationUri = null,
        private ?string $verificationRoute = null,
        private DeviceScopeFamilyRegistry $families = new DeviceScopeFamilyRegistry(),
    ) {
    }

    /**
     * POST device/code `{clientType: desktop|extension, clientName, scopes?, family?}`: starts a device authorization.
     * Since 2.2.0 an `Authorization: Bearer <token>` header (optional) names a token the device already holds: the new
     * token joins that device (`401 invalid_token` when that token is not valid).
     */
    #[Route('/api/time-track/device/code', name: 'nowo_time_track_api_device_code', methods: ['POST', 'OPTIONS'])]
    public function code(Request $request): Response
    {
        $early = $this->preflightOrLimit($request);
        if ($early instanceof Response) {
            return $early;
        }

        /** @var mixed $payload */
        $payload    = json_decode($request->getContent(), true);
        $payload    = is_array($payload) ? $payload : [];
        $clientType = ClientType::tryFrom(is_string($payload['clientType'] ?? null) ? $payload['clientType'] : 'desktop');
        if ($clientType === null || $clientType === ClientType::Web) {
            return $this->responseFactory->error('invalid_request', 'clientType must be desktop or extension.', Response::HTTP_BAD_REQUEST, $request);
        }

        try {
            $scopes = $this->families->resolve($payload['family'] ?? null, $payload['scopes'] ?? null);
        } catch (InvalidArgumentException) {
            return $this->responseFactory->error('invalid_scope', 'scopes must be a non-empty list of known scopes of one scope family (or omitted for the default time scopes); family must be a registered scope family.', Response::HTTP_BAD_REQUEST, $request);
        }

        $deviceKey = null;
        $linked    = ClientApiGuard::bearerToken($request);
        if ($linked !== null) {
            $deviceKey = $this->guard->deviceKeyOf($linked, $request->getClientIp());
            if ($deviceKey === null) {
                return $this->responseFactory->error('invalid_token', 'The token of this device is invalid, expired or revoked: sign in again.', Response::HTTP_UNAUTHORIZED, $request, [
                    'WWW-Authenticate' => 'Bearer error="invalid_token"',
                ]);
            }
        }

        try {
            $grant = $this->deviceService->startFamily($clientType, is_string($payload['clientName'] ?? null) ? $payload['clientName'] : '', $scopes, $request->getClientIp(), $deviceKey);
        } catch (DeviceFlowCapacityExceededException $exception) {
            return $this->responseFactory->error('rate_limited', 'Too many pending device requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, [
                'Retry-After' => (string) $exception->retryAfter,
            ]);
        }

        $uri = $this->verificationUri();

        return $this->responseFactory->json($grant->toArray() + [
            'scopeFamily'             => $scopes->family,
            'verificationUri'         => $uri,
            'verificationUriComplete' => $uri . (str_contains($uri, '?') ? '&' : '?') . 'code=' . rawurlencode($grant->userCode),
        ], Response::HTTP_OK, $request);
    }

    /**
     * POST device/token `{deviceCode}`: `authorization_pending`, `slow_down`, `expired_token`, `access_denied` (400)
     * or `{token, expiresAt, scopes}` (200, once).
     */
    #[Route('/api/time-track/device/token', name: 'nowo_time_track_api_device_token', methods: ['POST', 'OPTIONS'])]
    public function token(Request $request): Response
    {
        $early = $this->preflightOrLimit($request);
        if ($early instanceof Response) {
            return $early;
        }

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $code    = is_array($payload) && is_string($payload['deviceCode'] ?? null) ? $payload['deviceCode'] : '';
        $result  = $this->deviceService->poll($code, $request->getClientIp());

        if ($result->token !== null) {
            return $this->responseFactory->json($result->token, Response::HTTP_OK, $request);
        }

        return $this->responseFactory->error((string) $result->error, 'Device authorization: ' . $result->error . '.', Response::HTTP_BAD_REQUEST, $request);
    }

    private function preflightOrLimit(Request $request): ?Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        return $this->guard->limitIp($request, true);
    }

    private function verificationUri(): string
    {
        if ($this->verificationUri !== null && $this->verificationUri !== '') {
            return $this->verificationUri;
        }

        return $this->urlGenerator->generate((string) $this->verificationRoute, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
