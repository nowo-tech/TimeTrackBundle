<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientAllocationInput;
use Nowo\TimeTrackBundle\Client\ClientAllocationPresenter;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;
use Nowo\TimeTrackBundle\Entity\ActiveTimer;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Exception\ActiveTimerConflictException;
use Nowo\TimeTrackBundle\Exception\TaskNotTrackableException;
use Nowo\TimeTrackBundle\Exception\TimeAllocationException;
use Nowo\TimeTrackBundle\Port\Internal\TaskAccessCheckerInterface;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Routing\TimeTrackRouteLoader;
use Nowo\TimeTrackBundle\Service\TimerService;
use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;
use function is_array;
use function json_decode;
use function preg_match;

/**
 * Since 2.1.0 (Beacon spec 256, client API contract v0.4): the subject endpoints of the desktop and extension clients.
 * No new client scope (Q-TS-12): existing tokens keep working without a new sign-in.
 *
 * - `POST timer/allocate` (`timer:write`) `{subject?, activityTypeKey?}`: sets, changes or clears (`subject: null`) the
 *   subject and activity type of the running timer, task timer or not (a task timer's task access is checked again when
 *   its subject changes: 403 `task_forbidden`), under the owner's lock (last write wins,
 *   `TimerAllocatedEvent`); 404 `no_active_timer`, 403 `subject_forbidden`, 422 `allocation_conflict` /
 *   `activity_type_unknown` / `activity_type_inactive`, 409 `allocation_required` when clearing would leave the time
 *   unallocated.
 * - `GET subjects/search?type=&q=&limit=&scopeKey=` (`tasks:read`): the provider's search for the token's user in one of
 *   their scopes (default: the current one); `limit` ≤ 50, `q` ≤ 100 characters, rate-limited per token
 *   (`entries.subjects.search.max_per_minute`). Results are a hint: every write checks `canLog` again (Q-TS-4).
 * - `GET activity-types?scopeKey=` (`tasks:read`): the active activity types of one of the user's scopes, rate-limited
 *   per token like the search.
 *
 * Labels are plain text (JSON-encoded); subject URLs follow the task URL rule. Stateless.
 */
#[Route(requirements: TimeTrackRouteLoader::REQUIREMENTS)]
final readonly class TimeTrackClientAllocationApiController
{
    /**
     * @param int $searchPerMinute `entries.subjects.search.max_per_minute`
     */
    public function __construct(
        private ClientApiGuard $guard,
        private ClientResponseFactory $responseFactory,
        private ClientApiRateLimiter $rateLimiter,
        private ClientApiAuditor $auditor,
        private TimerService $timerService,
        private ClientAllocationPresenter $allocations,
        private ClientTaskPresenter $taskPresenter,
        private int $searchPerMinute = 60,
        // Audit ZBQ-L3: the task of a running task timer is checked again when its subject changes.
        private ?TaskAccessCheckerInterface $taskAccess = null,
    ) {
    }

    /**
     * POST timer/allocate: changes the running timer's subject and activity type.
     */
    #[Route('/api/time-track/timer/allocate', name: 'nowo_time_track_api_timer_allocate', methods: ['POST', 'OPTIONS'])]
    public function allocate(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimerWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        try {
            $patch = ClientAllocationInput::patch($this->payload($request));
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        }
        if ($patch->isKeep()) {
            return $this->responseFactory->error('invalid_request', 'Send subject and/or activityTypeKey.', Response::HTTP_BAD_REQUEST, $request);
        }

        $active = $patch->changesSubject ? $this->timerService->getActive($principal->user) : null;
        if ($active instanceof ActiveTimer && $active->hasTask() && $patch->changesSubjectOf($active->subject()) && $this->taskAccess instanceof TaskAccessCheckerInterface && !$this->taskAccess->canTrack($principal->user, $active->getTaskId())) {
            return $this->taskForbidden($principal, $request);
        }

        try {
            $timer = $this->timerService->allocateRunning($principal->user, $patch);
        } catch (TimeAllocationException $e) {
            return $this->refused($e, $principal, $request);
        } catch (TaskNotTrackableException) {
            return $this->taskForbidden($principal, $request);
        } catch (ActiveTimerConflictException) {
            return $this->responseFactory->error('timer_running', 'The running timer changed; try again.', Response::HTTP_CONFLICT, $request);
        }
        if (!$timer instanceof ActiveTimer) {
            return $this->responseFactory->error('no_active_timer', 'No active timer.', Response::HTTP_NOT_FOUND, $request);
        }
        if ($patch->changesSubject && $patch->subject instanceof TimeSubjectRef) {
            $this->auditor->record(ClientApiAuditAction::SubjectAllocated, $principal->user, $principal->token->getCredentialId(), $principal->token->getClientType(), $request->getClientIp(), $patch->subject->type, $principal->token->getScopeFamily());
        }

        return $this->responseFactory->json([
            'timer' => $this->taskPresenter->timer($timer, $principal->user, $request) + $this->allocations->timer($principal->user, $timer, $request),
        ], Response::HTTP_OK, $request);
    }

    /**
     * GET subjects/search: subjects the user may log on.
     */
    #[Route('/api/time-track/subjects/search', name: 'nowo_time_track_api_subjects_search', methods: ['GET', 'OPTIONS'])]
    public function search(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TasksRead);
        if ($principal instanceof Response) {
            return $principal;
        }

        $limit = $request->query->getString('limit', (string) TimeSubjectSearch::DEFAULT_LIMIT);
        try {
            $search = new TimeSubjectSearch($request->query->getString('type'), $request->query->getString('q'), preg_match('/^[1-9][0-9]{0,2}$/D', $limit) === 1 ? (int) $limit : 0);
        } catch (InvalidArgumentException $e) {
            return $this->responseFactory->error('invalid_request', $e->getMessage(), Response::HTTP_BAD_REQUEST, $request);
        }
        $scope = $this->scope($principal, $request);
        if ($scope instanceof Response) {
            return $scope;
        }
        $retry = $this->rateLimiter->hit('subjects_search', $principal->token->getTokenHash(), $this->searchPerMinute, 60);
        if ($retry > 0) {
            return $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, ['Retry-After' => (string) $retry]);
        }

        $results = $this->allocations->guard()->subjects()->search(new TimeSubjectContext(TimeTrackUserRefs::fromUser($principal->user), $scope ?? ''), $search);

        return $this->responseFactory->json([
            'subjects' => array_map(fn (TimeSubjectLabel $label): array => [
                'type'  => $label->subject->type,
                'id'    => $label->subject->id,
                'label' => $label->label,
                'hint'  => $label->hint,
                'url'   => $this->taskPresenter->safeUrl($label->url, $request),
            ], $results),
        ], Response::HTTP_OK, $request);
    }

    /**
     * GET activity-types: the active activity types of one of the user's scopes.
     */
    #[Route('/api/time-track/activity-types', name: 'nowo_time_track_api_activity_types', methods: ['GET', 'OPTIONS'])]
    public function activityTypes(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TasksRead);
        if ($principal instanceof Response) {
            return $principal;
        }
        $scope = $this->scope($principal, $request);
        if ($scope instanceof Response) {
            return $scope;
        }
        // Audit ZBQ: one host call per request, rate-limited per token like the subject search.
        $retry = $this->rateLimiter->hit('activity_types', $principal->token->getTokenHash(), $this->searchPerMinute, 60);
        if ($retry > 0) {
            return $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, ['Retry-After' => (string) $retry]);
        }

        return $this->responseFactory->json([
            'scopeKey'      => $scope,
            'activityTypes' => array_map(static fn (ActivityType $type): array => ['key' => $type->key, 'label' => $type->label, 'group' => $type->group], $this->allocations->guard()->activityTypes()->listFor($scope)->active()),
        ], Response::HTTP_OK, $request);
    }

    /**
     * The requested `scopeKey` when it is one of the user's (else the current scope; null without a scope provider), or
     * the 400 answer.
     */
    private function scope(ClientApiPrincipal $principal, Request $request): string|Response|null
    {
        $requested = $request->query->has('scopeKey') ? $request->query->getString('scopeKey') : null;
        try {
            return $this->timerService->userScope($principal->user, ClientAllocationInput::scopeKey(['scopeKey' => $requested]));
        } catch (InvalidArgumentException|TimeAllocationException) {
            return $this->responseFactory->error('invalid_request', 'scopeKey must be one of your scope keys.', Response::HTTP_BAD_REQUEST, $request);
        }
    }

    /**
     * The answer to a refused subject or activity type.
     */
    private function refused(TimeAllocationException $e, ClientApiPrincipal $principal, Request $request): JsonResponse
    {
        if ($e->errorCode === TimeAllocationException::SUBJECT_FORBIDDEN) {
            $this->auditor->denied('subject_forbidden', $principal->token, $request->getClientIp());

            return $this->responseFactory->error('subject_forbidden', 'You may not log time on this subject.', Response::HTTP_FORBIDDEN, $request);
        }

        return $this->responseFactory->error($e->errorCode, 'The allocation of this time was refused.', $e->httpStatus(), $request);
    }

    /**
     * 403 `task_forbidden` (audited, throttled), as on `timer/start`.
     */
    private function taskForbidden(ClientApiPrincipal $principal, Request $request): JsonResponse
    {
        $this->auditor->denied('task_forbidden', $principal->token, $request->getClientIp());

        return $this->responseFactory->error('task_forbidden', 'You may not track time on this task.', Response::HTTP_FORBIDDEN, $request);
    }

    /**
     * Answers a CORS preflight, or authenticates the request and checks the scope.
     */
    private function authorize(Request $request, ClientScope $scope): ClientApiPrincipal|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        return $this->guard->authenticate($request, $scope);
    }

    /**
     * Decoded JSON object body (empty array for anything else).
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);

        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) ? $decoded : [];

        return $payload;
    }
}
