<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientIdempotencyStore;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Client\ClientWorkingTimeContext;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Entity\TimesheetEvent;
use Nowo\TimeTrackBundle\Enum\AuditClient;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\TimesheetStatus;
use Nowo\TimeTrackBundle\Exception\TimesheetException;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\Period;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Nowo\TimeTrackBundle\Workday\WorkdayAction;
use Nowo\TimeTrackBundle\Workday\WorkdayConflictException;
use Nowo\TimeTrackBundle\Workday\WorkdayForbiddenException;
use Nowo\TimeTrackBundle\Workday\WorkdayProviderInterface;
use Nowo\TimeTrackBundle\Workday\WorkdayState;
use Nowo\TimeTrackBundle\WorkingTime\BalanceReport;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function hash;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function strlen;

use const DATE_ATOM;

/**
 * Since 1.13 (contract §10.2): the working-time routes of the client API — `GET workday` and the workday actions
 * (R-GDPR §8; host register through `client_api.workday_provider`), `GET balance`, `GET timesheets` and the owner's
 * `submit` / `withdraw`. Same guard as every client route (Bearer, scopes, rate limits, CORS, credential state); the 1.13
 * scopes need an explicit grant. A feature that is off, a scope the user is not in and other users' data all answer
 * 404 `not_found`. Approve / reject / reopen and the ledger are web only.
 */
final readonly class TimeTrackWorkingTimeApiController
{
    /** Longest balance range of one request, in days. */
    public const BALANCE_MAX_DAYS = 92;

    /** Oldest accepted `occurredAt` of a workday action, in seconds (72 h). */
    public const OCCURRED_AT_MAX_AGE = 259_200;

    /** Accepted clock skew of a future `occurredAt`, in seconds. */
    public const OCCURRED_AT_SKEW = 120;

    /** `Idempotency-Key` characters. */
    public const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{1,64}$/D';

    private ClockInterface $clock;

    /**
     * Receives the client API collaborators and the working-time context.
     */
    public function __construct(
        private ClientApiGuard $guard,
        private ClientResponseFactory $responseFactory,
        private ClientWorkingTimeContext $context,
        private ClientIdempotencyStore $idempotency,
        private ClientApiAuditor $auditor,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * GET workday: today's working day (404 when the register is off for the user).
     */
    public function workday(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::WorkdayRead);
        if ($principal instanceof Response) {
            return $principal;
        }
        $state = $this->context->workdayProvider()?->today($principal->user);

        return $state instanceof WorkdayState ? $this->responseFactory->json($state->toArray(), Response::HTTP_OK, $request) : $this->notFound($request);
    }

    /**
     * POST workday/{action} `{occurredAt?}` with `Idempotency-Key`: clock in / out, start / end a break.
     */
    public function workdayAction(Request $request, string $action): Response
    {
        $principal = $this->authorize($request, ClientScope::WorkdayWrite);
        if ($principal instanceof Response) {
            return $principal;
        }
        $provider = $this->context->workdayProvider();
        $verb     = WorkdayAction::tryFrom($action);
        if (!$provider instanceof WorkdayProviderInterface || !$verb instanceof WorkdayAction || !$provider->today($principal->user) instanceof WorkdayState) {
            return $this->notFound($request);
        }
        $key = (string) $request->headers->get('Idempotency-Key', '');
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            return $this->responseFactory->error('invalid_request', 'The Idempotency-Key header (1-64 visible characters) is required.', Response::HTTP_BAD_REQUEST, $request);
        }
        $payload  = $this->payload($request);
        $raw      = $payload['occurredAt'] ?? null;
        $userId   = UserIdResolver::getId($principal->user);
        $bodyHash = hash('sha256', $verb->value . "\0" . (is_string($raw) ? $raw : ''));
        $replay   = $this->idempotency->find($userId, $key, $bodyHash);
        if ($replay === false) {
            return $this->responseFactory->error('idempotency_mismatch', 'This Idempotency-Key was used with another request.', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }
        if ($replay !== null) {
            return $this->responseFactory->json($replay['body'], $replay['status'], $request);
        }

        $occurredAt = $this->occurredAt($raw);
        if ($occurredAt === false) {
            return $this->responseFactory->error('invalid_occurred_at', 'occurredAt must be an ISO 8601 time, not in the future and at most 72 hours old.', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }

        try {
            $status = Response::HTTP_OK;
            $body   = $provider->act($principal->user, $verb, $occurredAt, $key, $principal->token->getClientType())->toArray();
        } catch (WorkdayConflictException $e) {
            $status = Response::HTTP_CONFLICT;
            $body   = ['error' => 'invalid_transition', 'message' => 'This action is not allowed now.', 'state' => $e->state->toArray()];
        } catch (WorkdayForbiddenException) {
            $this->auditor->denied('forbidden', $principal->token, $request->getClientIp());

            return $this->responseFactory->error('forbidden', 'Forbidden.', Response::HTTP_FORBIDDEN, $request);
        }
        $this->idempotency->save($userId, $key, $bodyHash, $status, $body);
        $this->auditor->record(ClientApiAuditAction::WorkdayAction, $principal->user, $principal->token->getCredentialId(), $principal->token->getClientType(), $request->getClientIp(), $verb->value);

        return $this->responseFactory->json($body, $status, $request);
    }

    /**
     * GET balance `?from&to&scope`: the hours balance (≤ 92 days; default: the current period), with `source` and
     * `legalRecord`.
     */
    public function balance(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::BalanceRead);
        if ($principal instanceof Response) {
            return $principal;
        }
        $scope = $this->context->scope($principal->user, $request->query->getString('scope') ?: null);
        if (!$scope instanceof TimesheetScope || !$this->context->balance()->isEnabled()) {
            return $this->notFound($request);
        }
        $current = Period::containing($this->context->timesheets()->periodKind(), $this->context->balance()->today($principal->user, $scope->key));
        $from    = $request->query->getString('from') ?: $current->start;
        $to      = $request->query->getString('to') ?: $current->end;
        try {
            $days = Period::date($from)->diff(Period::date($to));
            if ($days->invert === 1 || $days->days >= self::BALANCE_MAX_DAYS) {
                throw new InvalidArgumentException('range');
            }
            $report = $this->context->balance()->report($principal->user, $scope->key, $from, $to);
        } catch (InvalidArgumentException) {
            return $this->responseFactory->error('invalid_request', 'from and to must be Y-m-d dates, from ≤ to, at most 92 days.', Response::HTTP_BAD_REQUEST, $request);
        }

        return $report instanceof BalanceReport ? $this->responseFactory->json(['scope' => $this->scopeArray($scope)] + $report->toArray(), Response::HTTP_OK, $request) : $this->notFound($request);
    }

    /**
     * GET timesheets `?period&scope`: the user's timesheet of the period (`current`, `previous` or a key) with its
     * entries, totals, audit trail and allowed actions.
     */
    public function timesheet(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::TimesheetRead);
        if ($principal instanceof Response) {
            return $principal;
        }

        return $this->run($request, $principal, $request->query->getString('scope') ?: null, function (TimesheetScope $scope) use ($request, $principal): Timesheet {
            $period = $this->context->timesheets()->period($principal->user, $scope->key, $request->query->getString('period') ?: null);

            return $this->context->timesheets()->view($principal->user, UserIdResolver::getId($principal->user), $scope->key, $period);
        });
    }

    /**
     * POST timesheets/{periodKey}/submit `{scope?}`: submits the period (owner only).
     */
    public function timesheetSubmit(Request $request, string $periodKey): Response
    {
        return $this->transition($request, $periodKey, true);
    }

    /**
     * POST timesheets/{periodKey}/withdraw `{scope?}`: withdraws a submitted period.
     */
    public function timesheetWithdraw(Request $request, string $periodKey): Response
    {
        return $this->transition($request, $periodKey, false);
    }

    private function transition(Request $request, string $periodKey, bool $submit): Response
    {
        $principal = $this->authorize($request, ClientScope::TimesheetWrite);
        if ($principal instanceof Response) {
            return $principal;
        }
        $scope  = $this->payload($request)['scope'] ?? null;
        $client = AuditClient::fromClientType($principal->token->getClientType());

        return $this->run($request, $principal, is_string($scope) ? $scope : null, function (TimesheetScope $scope) use ($principal, $periodKey, $submit, $client, $request): Timesheet {
            $service = $this->context->timesheets();
            $period  = $service->period($principal->user, $scope->key, $periodKey);
            $sheet   = $submit ? $service->submit($principal->user, $scope->key, $period, $client) : $service->withdraw($principal->user, $scope->key, $period, $client);
            $this->auditor->record($submit ? ClientApiAuditAction::TimesheetSubmitted : ClientApiAuditAction::TimesheetWithdrawn, $principal->user, $principal->token->getCredentialId(), $principal->token->getClientType(), $request->getClientIp());

            return $sheet;
        });
    }

    /**
     * Runs a timesheet operation in the user's scope and answers the timesheet, or the error.
     *
     * @param callable(TimesheetScope): Timesheet $operation
     */
    private function run(Request $request, ClientApiPrincipal $principal, ?string $scopeKey, callable $operation): JsonResponse
    {
        $scope = $this->context->scope($principal->user, $scopeKey);
        if (!$scope instanceof TimesheetScope || !$this->context->timesheets()->isEnabled()) {
            return $this->notFound($request);
        }
        try {
            $timesheet = $operation($scope);
        } catch (TimesheetException $e) {
            return $this->responseFactory->error($e->errorCode(), 'The timesheet action was refused.', $e->httpStatus(), $request);
        }

        return $this->responseFactory->json($this->timesheetArray($timesheet, $scope), Response::HTTP_OK, $request);
    }

    /**
     * The owner's view of a timesheet.
     *
     * @return array<string, mixed>
     */
    private function timesheetArray(Timesheet $timesheet, TimesheetScope $scope): array
    {
        $service  = $this->context->timesheets();
        $snapshot = $service->snapshot($timesheet);
        $blocked  = $this->context->blockedEntryIds([...$snapshot->entries, ...$snapshot->unassigned]);
        $status   = $timesheet->getStatus();
        $actions  = [];
        if ($status->isEditable()) {
            $actions[] = 'submit';
        }
        if ($status === TimesheetStatus::Submitted) {
            $actions[] = 'withdraw';
        }
        $period = $timesheet->getPeriod();

        return [
            'id'              => $timesheet->getId(),
            'scope'           => $this->scopeArray($scope),
            'period'          => ['key' => $period->key, 'kind' => $period->kind->value, 'start' => $period->start, 'end' => $period->end, 'timezone' => $timesheet->getTimezone()],
            'status'          => $status->value,
            'locked'          => $timesheet->isLocked(),
            'lockedThrough'   => ClientWorkingTimeContext::lockedThrough($timesheet),
            'lockReason'      => $timesheet->getLockReason()?->value,
            'decisionComment' => $timesheet->getDecisionComment(),
            'totals'          => $status->isEditable() ? $snapshot->totals : ($timesheet->getTotals() ?? $snapshot->totals),
            'entries'         => array_map(static fn (TimeEntry $e): array => $e->toArray() + ['locked' => isset($blocked[$e->getId()])], $snapshot->entries),
            'unassigned'      => array_map(static fn (TimeEntry $e): array => $e->toArray() + ['locked' => isset($blocked[$e->getId()])], $snapshot->unassigned),
            'events'          => array_map(static fn (TimesheetEvent $e): array => [
                'action'     => $e->getAction()->value,
                'fromStatus' => $e->getFromStatus()->value,
                'toStatus'   => $e->getToStatus()->value,
                'actorRole'  => $e->getActorRole()->value,
                'client'     => $e->getClient()->value,
                'occurredAt' => $e->getOccurredAt()->format(DATE_ATOM),
                'comment'    => $e->getComment(),
            ], $service->events($timesheet)),
            'allowedActions' => $actions,
        ];
    }

    /**
     * @return array{key: string, label: string}
     */
    private function scopeArray(TimesheetScope $scope): array
    {
        return ['key' => $scope->key, 'label' => $scope->label];
    }

    /**
     * Parsed `occurredAt`: null when absent, false when invalid, in the future or older than 72 h.
     */
    private function occurredAt(mixed $raw): DateTimeImmutable|false|null
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw) || strlen($raw) > 40 || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $raw) !== 1) {
            return false;
        }
        try {
            $time = new DateTimeImmutable($raw);
        } catch (Exception) {
            return false;
        }
        $now = $this->clock->now()->getTimestamp();
        $ts  = $time->getTimestamp();

        return $ts > $now + self::OCCURRED_AT_SKEW || $ts < $now - self::OCCURRED_AT_MAX_AGE ? false : $time;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) ? $decoded : [];

        return $payload;
    }

    private function authorize(Request $request, ClientScope $scope): ClientApiPrincipal|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        return $this->guard->authenticate($request, $scope);
    }

    private function notFound(Request $request): JsonResponse
    {
        return $this->responseFactory->error('not_found', 'Not found.', Response::HTTP_NOT_FOUND, $request);
    }
}
