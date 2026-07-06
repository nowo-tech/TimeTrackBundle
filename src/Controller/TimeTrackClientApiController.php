<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientLoginRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Service\ClientAuthService;
use Nowo\TimeTrackBundle\Service\TeamAccessGuard;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;

use const DATE_ATOM;

/**
 * Browser extension and Tauri desktop API (Bearer token auth, CORS).
 */
final readonly class TimeTrackClientApiController
{
    public function __construct(
        private ClientAuthService $authService,
        private TimerService $timerService,
        private TeamAccessGuard $teamAccessGuard,
        private ClientResponseFactory $responseFactory,
        private ClientLoginRateLimiter $loginRateLimiter,
        private int $idleThresholdSeconds,
    ) {
    }

    public function login(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        /** @var mixed $payload */
        $payload    = json_decode($request->getContent(), true);
        $username   = is_array($payload) ? (string) ($payload['username'] ?? '') : '';
        $password   = is_array($payload) ? (string) ($payload['password'] ?? '') : '';
        $clientType = $this->resolveClientType(is_array($payload) ? (string) ($payload['clientType'] ?? 'extension') : 'extension');

        if ($username === '' || $password === '') {
            return $this->responseFactory->json(['error' => 'username and password are required.'], Response::HTTP_BAD_REQUEST, $request);
        }

        $clientIp = (string) $request->getClientIp();
        if ($this->loginRateLimiter->isLimited($clientIp, $username)) {
            return $this->responseFactory->json(['error' => 'Too many login attempts. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS, $request);
        }

        $result = $this->authService->login($username, $password, $clientType);
        if ($result === null) {
            $this->loginRateLimiter->registerFailedAttempt($clientIp, $username);

            return $this->responseFactory->json(['error' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $this->loginRateLimiter->reset($clientIp, $username);

        return $this->responseFactory->json($result, Response::HTTP_OK, $request);
    }

    public function logout(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $token = $this->extractBearerToken($request);
        if ($token !== null) {
            $this->authService->logout($token);
        }

        return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
    }

    public function me(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        return $this->responseFactory->json([
            'userId'     => UserIdResolver::getId($user),
            'identifier' => $user->getUserIdentifier(),
        ], Response::HTTP_OK, $request);
    }

    public function tasks(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $query = new TaskListQuery(
            search: $request->query->getString('search') ?: null,
            limit: max(1, $request->query->getInt('limit', 50)),
            offset: max(0, $request->query->getInt('offset', 0)),
        );

        $tasks = $this->timerService->listTasks($user, $query);

        return $this->responseFactory->json([
            'tasks' => array_map(static fn (\Nowo\TimeTrackBundle\Dto\TaskReference $task): array => $task->toArray(), $tasks),
        ], Response::HTTP_OK, $request);
    }

    public function timer(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $active = $this->timerService->getActive($user);
        if (!$active instanceof \Nowo\TimeTrackBundle\Entity\ActiveTimer) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        return $this->responseFactory->json(['timer' => $active->toArray()], Response::HTTP_OK, $request);
    }

    public function timerStart(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $taskId  = is_array($payload) ? (string) ($payload['taskId'] ?? '') : '';
        if ($taskId === '') {
            return $this->responseFactory->json(['error' => 'taskId is required.'], Response::HTTP_BAD_REQUEST, $request);
        }

        $clientType = $this->resolveClientType(is_array($payload) ? (string) ($payload['clientType'] ?? 'extension') : 'extension');
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($payload) && isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];

        try {
            $timer = $this->timerService->start($user, $taskId, $clientType, $metadata);
        } catch (ActiveTimerConflictException $e) {
            return $this->responseFactory->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT, $request);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST, $request);
        }

        return $this->responseFactory->json(['timer' => $timer->toArray()], Response::HTTP_CREATED, $request);
    }

    public function timerStop(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $entry = $this->timerService->stop($user);
        if (!$entry instanceof \Nowo\TimeTrackBundle\Entity\TimeEntry) {
            return $this->responseFactory->json(['error' => 'No active timer.'], Response::HTTP_NOT_FOUND, $request);
        }

        return $this->responseFactory->json(['entry' => $entry->toArray()], Response::HTTP_OK, $request);
    }

    public function heartbeat(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $isIdle  = is_array($payload) && (bool) ($payload['isIdle'] ?? false);

        $timer = $this->timerService->heartbeat($user, $isIdle);
        if (!$timer instanceof \Nowo\TimeTrackBundle\Entity\ActiveTimer) {
            return $this->responseFactory->json(['error' => 'No active timer.'], Response::HTTP_NOT_FOUND, $request);
        }

        return $this->responseFactory->json([
            'timer'                => $timer->toArray(),
            'idleThresholdSeconds' => $this->idleThresholdSeconds,
        ], Response::HTTP_OK, $request);
    }

    public function entries(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $from         = $this->parseDate($request->query->getString('from'), '-7 days');
        $to           = $this->parseDate($request->query->getString('to'), 'now');
        $targetUserId = $request->query->getString('userId') ?: null;

        if ($targetUserId !== null && !$this->teamAccessGuard->canViewUserEntries($user, $targetUserId)) {
            return $this->responseFactory->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN, $request);
        }

        $entries = $this->timerService->listEntries($user, $from, $to, $targetUserId);

        return $this->responseFactory->json([
            'entries' => array_map(static fn (\Nowo\TimeTrackBundle\Entity\TimeEntry $entry): array => $entry->toArray(), $entries),
        ], Response::HTTP_OK, $request);
    }

    private function resolveBearerUser(Request $request): ?UserInterface
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return null;
        }

        return $this->authService->resolveUser($token);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    private function resolveClientType(string $value): ClientType
    {
        return ClientType::tryFrom($value) ?? ClientType::Extension;
    }

    private function parseDate(string $value, string $fallback): DateTimeImmutable
    {
        if ($value !== '') {
            $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return new DateTimeImmutable($fallback);
    }
}
