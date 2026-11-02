<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\DeviceFlowCapacityExceededException;
use Nowo\TimeTrackBundle\Service\DeviceAuthorizationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function is_array;
use function is_string;
use function json_decode;
use function rawurlencode;
use function str_contains;

/**
 * Device flow endpoints of the client API (contract v0.2 §2): `POST device/code` and `POST device/token`. Stateless,
 * no Bearer, limited per IP (`client_api.rate_limit.device_per_ip`).
 */
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
    ) {
    }

    /**
     * POST device/code `{clientType: desktop|extension, clientName, scopes?}`: starts a device authorization.
     */
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

        $scopes = $payload['scopes'] ?? null;
        try {
            if ($scopes !== null && !is_array($scopes)) {
                throw new InvalidArgumentException('scopes must be a list.');
            }
            $scopes = ClientScope::fromRequested($scopes);
        } catch (InvalidArgumentException) {
            return $this->responseFactory->error('invalid_scope', 'scopes must be a non-empty list of known scopes (or omitted for every scope).', Response::HTTP_BAD_REQUEST, $request);
        }

        try {
            $grant = $this->deviceService->start($clientType, is_string($payload['clientName'] ?? null) ? $payload['clientName'] : '', $scopes, $request->getClientIp());
        } catch (DeviceFlowCapacityExceededException $exception) {
            return $this->responseFactory->error('rate_limited', 'Too many pending device requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, [
                'Retry-After' => (string) $exception->retryAfter,
            ]);
        }

        $uri = $this->verificationUri();

        return $this->responseFactory->json($grant->toArray() + [
            'verificationUri'         => $uri,
            'verificationUriComplete' => $uri . (str_contains($uri, '?') ? '&' : '?') . 'code=' . rawurlencode($grant->userCode),
        ], Response::HTTP_OK, $request);
    }

    /**
     * POST device/token `{deviceCode}`: `authorization_pending`, `slow_down`, `expired_token`, `access_denied` (400)
     * or `{token, expiresAt, scopes}` (200, once).
     */
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
