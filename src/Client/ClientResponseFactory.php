<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function in_array;
use function str_starts_with;

final readonly class ClientResponseFactory
{
    /**
     * @param list<string> $allowedOrigins use '*' to allow any origin (development only)
     */
    public function __construct(
        private array $allowedOrigins,
        private string $kernelEnvironment = 'prod',
    ) {
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $data
     */
    public function json(mixed $data, int $status, ?Request $request = null): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $this->applyCors($response, $request);

        return $response;
    }

    public function empty(int $status, ?Request $request = null): Response
    {
        $response = new Response('', $status);
        $this->applyCors($response, $request);

        return $response;
    }

    private function applyCors(Response $response, ?Request $request): void
    {
        if (!$request instanceof Request) {
            return;
        }

        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '') {
            return;
        }

        if (!$this->isOriginAllowed($origin)) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Vary', 'Origin');
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (($this->allowedOrigins === ['*'] || in_array('*', $this->allowedOrigins, true))
            && $this->kernelEnvironment !== 'prod'
        ) {
            return true;
        }

        if (in_array('*', $this->allowedOrigins, true)) {
            return false;
        }

        if (in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }

        return str_starts_with($origin, 'chrome-extension://')
            || str_starts_with($origin, 'moz-extension://')
            || str_starts_with($origin, 'tauri://');
    }
}
