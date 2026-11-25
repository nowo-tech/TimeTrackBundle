<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_values;
use function in_array;
use function preg_match;

/**
 * Builds the JSON / empty responses of the client API with the CORS headers of the allowed origins.
 *
 * CORS (contract v0.2 §4): exact origins only — `client_api.allowed_origins` (through
 * {@see ClientApiSettingsProviderInterface}) plus the constructor list (`clients.cors_allowed_origins`). The desktop
 * app (Tauri HTTP plugin) sends no Origin and needs no CORS header. Removed in 2.0 (deprecated since 1.9): `*` outside
 * prod, `clients.cors_allow_any_extension_origin`, and the Tauri webview origins (`client_api.allow_tauri_webview_origins`).
 * No `Access-Control-Allow-Credentials`: the API is Bearer-only. Origins from the settings provider (instance settings,
 * e.g. a database-backed form) must be exact `scheme://host[:port]` values: `*`, wildcards and anything with a path,
 * query or whitespace are ignored in every environment, so deny-by-default never depends on the host's form.
 */
final readonly class ClientResponseFactory
{
    /**
     * Origins of a Tauri webview (1.x desktop agent).
     *
     * @deprecated since 2.0, no longer allowed by the client API (`client_api.allow_tauri_webview_origins` is removed); removed in 3.0
     */
    public const TAURI_WEBVIEW_ORIGINS = ['tauri://localhost', 'http://tauri.localhost', 'https://tauri.localhost'];

    /**
     * @param list<string> $allowedOrigins exact origins (e.g. chrome-extension://<id>)
     * @param string $kernelEnvironment @deprecated since 2.0, ignored (it only decided the removed `*`); removed in 3.0
     * @param bool $allowAnyExtensionOrigin @deprecated since 2.0, ignored (`clients.cors_allow_any_extension_origin` is removed); removed in 3.0
     * @param ClientApiSettingsProviderInterface|null $settingsProvider instance settings (allowed origins); null = the constructor list only
     * @param string|null $contextHeader Since 2.2.0: the context header (`client_api.context.header`) browsers may send; null = none
     */
    // @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter
    public function __construct(
        private array $allowedOrigins,
        string $kernelEnvironment = 'prod',
        bool $allowAnyExtensionOrigin = false,
        private ?ClientApiSettingsProviderInterface $settingsProvider = null,
        private ?string $contextHeader = null,
    ) {
    }

    /**
     * JSON response with the CORS headers of the request origin.
     *
     * @param array<string, mixed>|list<mixed>|null $data
     */
    public function json(mixed $data, int $status, ?Request $request = null): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $this->applyCors($response, $request);

        return $response;
    }

    /**
     * Empty response with the CORS headers of the request origin.
     */
    public function empty(int $status, ?Request $request = null): Response
    {
        $response = new Response('', $status);
        $this->applyCors($response, $request);

        return $response;
    }

    /**
     * Error response `{error: code, message}` (contract v0.2 §3).
     *
     * @param string $code Machine-readable code (`invalid_token`, `insufficient_scope`, `task_forbidden`, `timer_running`, `rate_limited`, ...)
     * @param array<string, string> $headers Extra headers (e.g. `Retry-After`, `WWW-Authenticate`)
     * @param array<string, string> $extra Extra body keys, added after `error` and `message` (since 1.31.0: `code` of a conflict answer)
     */
    public function error(string $code, string $message, int $status, ?Request $request = null, array $headers = [], array $extra = []): JsonResponse
    {
        $response = $this->json(['error' => $code, 'message' => $message] + $extra, $status, $request);
        foreach ($headers as $name => $value) {
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function applyCors(Response $response, ?Request $request): void
    {
        if (!$request instanceof Request) {
            return;
        }

        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || !$this->isOriginAllowed($origin)) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Idempotency-Key' . ($this->contextHeader !== null ? ', ' . $this->contextHeader : ''));
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
        $response->headers->set('Vary', 'Origin');
    }

    private function isOriginAllowed(string $origin): bool
    {
        $settings = $this->settingsProvider?->getSettings();
        $allowed  = $settings instanceof ClientApiSettings ? [...$this->allowedOrigins, ...$this->exactOrigins($settings->allowedOrigins)] : $this->allowedOrigins;

        return $origin !== '*' && in_array($origin, $allowed, true);
    }

    /**
     * Keeps only exact origins (`scheme://host[:port]`, no wildcard, path, query or whitespace).
     *
     * @param list<string> $origins
     *
     * @return list<string>
     */
    private function exactOrigins(array $origins): array
    {
        return array_values(array_filter(
            $origins,
            static fn (string $origin): bool => preg_match('~\A[a-z][a-z0-9+.-]*://[^\s/?#*@]+\z~', $origin) === 1,
        ));
    }
}
