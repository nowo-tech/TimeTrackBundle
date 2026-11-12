<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Calendar\CalendarConnectionService;
use Nowo\TimeTrackBundle\Calendar\CalendarSettings;
use Nowo\TimeTrackBundle\Calendar\CalendarSuggestionService;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncResult;
use Nowo\TimeTrackBundle\Calendar\CalendarSyncService;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientIdempotencyStore;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\TimeEntrySource;
use Nowo\TimeTrackBundle\Exception\BillableChangeRefusedException;
use Nowo\TimeTrackBundle\Exception\IdempotentReplayException;
use Nowo\TimeTrackBundle\Exception\ManualEntryException;
use Nowo\TimeTrackBundle\Exception\PeriodLockedException;
use Nowo\TimeTrackBundle\Exception\PeriodWriteRefusedException;
use Nowo\TimeTrackBundle\Service\ManualEntryService;
use Nowo\TimeTrackBundle\Service\TimeEntryBillableService;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Support\Rfc3339;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScope;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_sum;
use function count;
use function hash;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;
use function max;
use function mb_strlen;
use function preg_match;
use function strlen;

use const DATE_ATOM;

/**
 * Since 1.14 (contract §6.6, §10.3): calendar suggestions and entries without a timer on the client API — `GET
 * calendar/connections`, `DELETE calendar/connections/{id}`, `GET suggestions` (`refresh=1` at most once per 5 minutes),
 * `POST suggestions/accept`, `POST suggestions/dismiss`, `POST suggestions/resolve-keys` (60 / min) and `POST entries`
 * (`manual_entries.enabled`, `Idempotency-Key`, 120 / h). Same guard as every client route; the 1.14 scopes need an
 * explicit grant. A feature that is off, a scope that is not the user's and other users' resources answer 404. No event
 * title ever reaches a response: only task ids, times and keyed ids.
 */
final readonly class TimeTrackCalendarApiController
{
    /** `refresh=1` of `GET suggestions`: once per window per token. */
    public const REFRESH_WINDOW_SECONDS = 300;

    /** `resolve-keys` calls per minute per token. */
    public const RESOLVE_KEYS_PER_MINUTE = 60;

    /** `POST entries` calls per hour per token. */
    public const ENTRIES_PER_HOUR = 120;

    /** `Idempotency-Key` characters. */
    public const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{1,64}$/D';

    private ClockInterface $clock;

    /**
     * Receives the client API collaborators and the calendar and entry services.
     */
    public function __construct(
        private ClientApiGuard $guard,
        private ClientResponseFactory $responseFactory,
        private ClientApiRateLimiter $rateLimiter,
        private ClientIdempotencyStore $idempotency,
        private CalendarConnectionService $connections,
        private CalendarSuggestionService $suggestions,
        private CalendarSyncService $sync,
        private ManualEntryService $entries,
        private CalendarSettings $settings,
        // Since 1.21: `PATCH entries/{id}` (`billable`).
        private TimeEntryBillableService $billable,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * GET calendar/connections `?scope`: the user's connections in the scope and the host *Connect* link per provider.
     */
    public function connections(Request $request): Response
    {
        return $this->noStore($this->doConnections($request));
    }

    /**
     * Answer of {@see self::connections()}.
     */
    private function doConnections(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::SuggestionsRead);
        if ($principal instanceof Response) {
            return $principal;
        }
        $scope = $this->scope($principal, $request->query->getString('scope') ?: null);
        if (!$scope instanceof TimesheetScope) {
            return $this->notFound($request);
        }

        return $this->responseFactory->json([
            'scopeKey'    => $scope->key,
            'connections' => array_map(static fn (CalendarConnection $c): array => $c->toArray(), $this->connections->connectionsOf($principal->user, $scope->key)),
            'connectUrls' => $this->connections->connectUrls($principal->user, $scope->key),
        ], Response::HTTP_OK, $request);
    }

    /**
     * DELETE calendar/connections/{id}: revokes one of the user's connections (host token and grant first, then the
     * connection, its suggestions and the user's rules in its scope).
     */
    public function connectionRevoke(Request $request, string $id): Response
    {
        return $this->noStore($this->doConnectionRevoke($request, $id));
    }

    /**
     * Answer of {@see self::connectionRevoke()}.
     */
    private function doConnectionRevoke(Request $request, string $id): Response
    {
        $principal = $this->authorize($request, ClientScope::SuggestionsWrite);
        if ($principal instanceof Response) {
            return $principal;
        }

        return $this->connections->revoke($principal->user, $id) ? $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request) : $this->notFound($request);
    }

    /**
     * GET suggestions `?scope&from&to&refresh`: pending suggestions grouped (dailies) and single, ended in the last
     * `calendar.lookback_days`. `refresh=1` syncs the user's server-mode connections first (once per 5 minutes).
     */
    public function suggestions(Request $request): Response
    {
        return $this->noStore($this->doSuggestions($request));
    }

    /**
     * Answer of {@see self::suggestions()}.
     */
    private function doSuggestions(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::SuggestionsRead);
        if ($principal instanceof Response) {
            return $principal;
        }
        $scope = $this->scope($principal, $request->query->getString('scope') ?: null);
        if (!$scope instanceof TimesheetScope) {
            return $this->notFound($request);
        }
        $now    = $this->clock->now();
        $oldest = $now->modify('-' . $this->settings->lookbackDays . ' days');
        $from   = $this->optionalTime($request->query->get('from'), $oldest);
        $to     = $this->optionalTime($request->query->get('to'), $now);
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || $to <= $from) {
            return $this->responseFactory->error('invalid_request', 'from and to must be RFC 3339 date-times with from before to.', Response::HTTP_BAD_REQUEST, $request);
        }
        $ignored = null;
        if ($request->query->getBoolean('refresh')) {
            $retry = $this->rateLimiter->hit('suggestions_refresh', $principal->token->getTokenHash(), 1, self::REFRESH_WINDOW_SECONDS);
            if ($retry > 0) {
                return $this->tooManyRequests($request, $retry);
            }
            $ignored = array_sum(array_map(static fn (CalendarSyncResult $r): int => $r->counts['ignored'], $this->sync->syncUser($principal->user, $scope->key)));
        }
        $from = max($from, $oldest);

        return $this->responseFactory->json([
            'scopeKey' => $scope->key,
            'from'     => $from->format(DATE_ATOM),
            'to'       => $to->format(DATE_ATOM),
            ...$this->suggestions->pending($principal->user, $scope->key, $from, $to),
            'ignored' => $ignored,
        ], Response::HTTP_OK, $request);
    }

    /**
     * POST suggestions/accept `{ids | groupKey, taskId?, note?}` (scopes `suggestions:write` and `entries:write`): one
     * `calendar` entry per occurrence; per-occurrence outcomes `{id, entryId?, error?}`.
     */
    public function accept(Request $request): Response
    {
        return $this->noStore($this->doAccept($request));
    }

    /**
     * Answer of {@see self::accept()}.
     */
    private function doAccept(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::SuggestionsWrite);
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!$principal->token->hasScope(ClientScope::EntriesWrite)) {
            return $this->responseFactory->error('insufficient_scope', 'The token lacks the "entries:write" scope.', Response::HTTP_FORBIDDEN, $request, [
                'WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="entries:write"',
            ]);
        }
        if (!$this->connections->isEnabled()) {
            return $this->notFound($request);
        }
        $payload   = $this->payload($request);
        $selection = $this->selection($payload);
        $taskId    = $payload['taskId'] ?? null;
        $note      = $payload['note'] ?? null;
        if ($selection === null || ($taskId !== null && (!is_string($taskId) || $taskId === '' || strlen($taskId) > 36)) || ($note !== null && !is_string($note))) {
            return $this->invalidSelection($request);
        }

        return $this->responseFactory->json(['outcomes' => $this->suggestions->accept($principal->user, $selection[0], $selection[1], $taskId, $note)], Response::HTTP_OK, $request);
    }

    /**
     * POST suggestions/dismiss `{ids | groupKey}`: the dismissed ids.
     */
    public function dismiss(Request $request): Response
    {
        return $this->noStore($this->doDismiss($request));
    }

    /**
     * Answer of {@see self::dismiss()}.
     */
    private function doDismiss(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::SuggestionsWrite);
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!$this->connections->isEnabled()) {
            return $this->notFound($request);
        }
        $selection = $this->selection($this->payload($request));
        if ($selection === null) {
            return $this->invalidSelection($request);
        }

        return $this->responseFactory->json(['dismissed' => $this->suggestions->dismiss($principal->user, $selection[0], $selection[1])], Response::HTTP_OK, $request);
    }

    /**
     * POST suggestions/resolve-keys `{scopeKey?, keys}` (local mode): `{matches: {key: {taskId, title, boardId,
     * trackable}}}`. Keys only reach the server (≤ 50, each ≤ 32 characters); 60 calls per minute per token.
     */
    public function resolveKeys(Request $request): Response
    {
        return $this->noStore($this->doResolveKeys($request));
    }

    /**
     * Answer of {@see self::resolveKeys()}.
     */
    private function doResolveKeys(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::SuggestionsRead);
        if ($principal instanceof Response) {
            return $principal;
        }
        $payload  = $this->payload($request);
        $scopeKey = $payload['scopeKey'] ?? null;
        $scope    = $this->scope($principal, is_string($scopeKey) ? $scopeKey : null);
        if (!$scope instanceof TimesheetScope) {
            return $this->notFound($request);
        }
        $keys = $payload['keys'] ?? null;
        if (!is_array($keys) || $keys === [] || count($keys) > CalendarSuggestionService::MAX_KEYS || !array_is_list($keys) || $this->hasInvalidKey($keys)) {
            return $this->responseFactory->error('invalid_request', 'keys must be a list of 1-50 strings of 1-32 characters.', Response::HTTP_BAD_REQUEST, $request);
        }
        $retry = $this->rateLimiter->hit('resolve_keys', $principal->token->getTokenHash(), self::RESOLVE_KEYS_PER_MINUTE, 60);
        if ($retry > 0) {
            return $this->tooManyRequests($request, $retry);
        }

        /* @var list<string> $keys */
        return $this->responseFactory->json(['matches' => (object) $this->suggestions->resolveKeys($principal->user, $scope->key, $keys)], Response::HTTP_OK, $request);
    }

    /**
     * POST entries `{taskId, startedAt, endedAt, source: manual|calendar, note?, billable?}` with `Idempotency-Key`: 201
     * entry; 409 `period_locked` / `period_submitted` / `overlap`; 422 for the times; 403 `task_forbidden`. Since 1.21 an
     * absent or null `billable` takes the host default.
     */
    public function createEntry(Request $request): Response
    {
        return $this->noStore($this->doCreateEntry($request));
    }

    /**
     * Answer of {@see self::createEntry()}.
     */
    private function doCreateEntry(Request $request): Response
    {
        $principal = $this->authorize($request, ClientScope::EntriesWrite);
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!$this->entries->isEnabled()) {
            return $this->notFound($request);
        }
        $key = (string) $request->headers->get('Idempotency-Key', '');
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            return $this->responseFactory->error('invalid_request', 'The Idempotency-Key header (1-64 visible characters) is required.', Response::HTTP_BAD_REQUEST, $request);
        }
        $payload  = $this->payload($request);
        $userId   = UserIdResolver::getId($principal->user);
        $bodyHash = hash('sha256', 'entries' . "\0" . CanonicalJson::encode($payload));
        $replay   = $this->idempotency->find($userId, 'entries:' . $key, $bodyHash);
        if ($replay === false) {
            return $this->responseFactory->error('idempotency_mismatch', 'This Idempotency-Key was used with another request.', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
        }
        if ($replay !== null) {
            return $this->responseFactory->json($replay['body'], $replay['status'], $request);
        }
        $taskId = $payload['taskId'] ?? null;
        $start  = Rfc3339::parse($payload['startedAt'] ?? null);
        $end    = Rfc3339::parse($payload['endedAt'] ?? null);
        $source = TimeEntrySource::tryFrom(is_string($payload['source'] ?? null) ? $payload['source'] : '');
        $note   = $payload['note'] ?? null;
        // Since 1.21: an optional `billable` (true / false; null or absent = the host default).
        $billable = $payload['billable'] ?? null;
        if (!is_string($taskId) || $taskId === '' || strlen($taskId) > 36 || !$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable
            || !in_array($source, [TimeEntrySource::Manual, TimeEntrySource::Calendar], true) || ($note !== null && !is_string($note)) || ($billable !== null && !is_bool($billable))) {
            return $this->responseFactory->error('invalid_request', 'taskId, startedAt and endedAt (RFC 3339) and source (manual or calendar) are required.', Response::HTTP_BAD_REQUEST, $request);
        }
        $retry = $this->rateLimiter->hit('entries_create', $principal->token->getTokenHash(), self::ENTRIES_PER_HOUR, 3600);
        if ($retry > 0) {
            return $this->tooManyRequests($request, $retry);
        }

        $idempotencyKey = 'entries:' . $key;
        try {
            // GG-L1: under the user's lock, a request with the same key whose answer is already stored is replayed; the
            // answer is stored right after the commit.
            // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
            $entry = $this->entries->create($principal->user, $taskId, $start, $end, $source, $note, ['origin' => $source->value], null, function () use ($userId, $idempotencyKey, $bodyHash): void {
                $again = $this->idempotency->find($userId, $idempotencyKey, $bodyHash);
                if ($again === false) {
                    throw new IdempotentReplayException(Response::HTTP_UNPROCESSABLE_ENTITY, ['error' => 'idempotency_mismatch', 'message' => 'This Idempotency-Key was used with another request.']);
                }
                if ($again !== null) {
                    throw new IdempotentReplayException($again['status'], $again['body']);
                }
                // @igor-ignore - Request-scoped closure passed to a per-request call; nothing is stored on the service.
            }, null, $billable);

            // Delta GG-D-3: stored after the commit (a failed commit never leaves a 201 to replay).
            $body = ['entry' => $entry->toArray() + ['locked' => false]];
            $this->idempotency->save($userId, $idempotencyKey, $bodyHash, Response::HTTP_CREATED, $body);

            return $this->responseFactory->json($body, Response::HTTP_CREATED, $request);
        } catch (IdempotentReplayException $replay) {
            return $this->responseFactory->json($replay->body, $replay->status, $request);
        } catch (ManualEntryException $e) {
            if ($e->errorCode === ManualEntryException::NOT_TRACKABLE) {
                return $this->responseFactory->error('task_forbidden', 'You may not track time on this task.', Response::HTTP_FORBIDDEN, $request);
            }
            $status = $e->httpStatus();
            $body   = ['error' => $e->errorCode, 'message' => 'The entry was refused.'];
        } catch (PeriodWriteRefusedException $e) {
            $status = Response::HTTP_CONFLICT;
            $body   = ['error' => $e->errorCode(), 'message' => 'The period is locked or submitted.'];
            if ($e instanceof PeriodLockedException) {
                $body['lockedThrough'] = $e->getLockedThrough();
            }
        }
        $this->idempotency->save($userId, $idempotencyKey, $bodyHash, $status, $body);

        return $this->responseFactory->json($body, $status, $request);
    }

    /**
     * Since 1.21: PATCH entries/{id} `{billable: true|false|null}` — changes the `billable` flag of one of the caller's own
     * live entries (scope `entries:write`): 200 entry; 400 `invalid_request` (no `billable`, not a boolean or null, or
     * another field); 404 `not_found` (no such entry, or another user's); 409 `read_only` (imported), `period_locked` /
     * `period_submitted`; 403 `forbidden` (a host `edit` access-check listener said no). Audited by
     * {@see TimeEntryBillableService}.
     */
    public function updateEntry(Request $request, string $id): Response
    {
        return $this->noStore($this->doUpdateEntry($request, $id));
    }

    /**
     * Answer of {@see self::updateEntry()}.
     */
    private function doUpdateEntry(Request $request, string $id): Response
    {
        $principal = $this->authorize($request, ClientScope::EntriesWrite);
        if ($principal instanceof Response) {
            return $principal;
        }
        $payload = $this->payload($request);
        if (!array_key_exists('billable', $payload) || array_keys($payload) !== ['billable'] || ($payload['billable'] !== null && !is_bool($payload['billable']))) {
            return $this->responseFactory->error('invalid_request', 'Send {"billable": true, false or null}; no other field can be changed.', Response::HTTP_BAD_REQUEST, $request);
        }
        $retry = $this->rateLimiter->hit('entries_update', $principal->token->getTokenHash(), self::ENTRIES_PER_HOUR, 3600);
        if ($retry > 0) {
            return $this->tooManyRequests($request, $retry);
        }
        $entry = $this->billable->find($id);
        // Own entries only: another user's entry looks like an unknown one.
        if (!$entry instanceof TimeEntry || $entry->getUserId() !== UserIdResolver::getId($principal->user)) {
            return $this->notFound($request);
        }

        try {
            $entry = $this->billable->change($principal->user, $entry, $payload['billable'], 'api');
        } catch (BillableChangeRefusedException $e) {
            return $this->responseFactory->error($e->errorCode, 'The billable flag of this entry cannot be changed.', $e->httpStatus(), $request);
        } catch (PeriodWriteRefusedException $e) {
            $extra = $e instanceof PeriodLockedException ? ['lockedThrough' => $e->getLockedThrough()] : [];

            return $this->responseFactory->json(['error' => $e->errorCode(), 'message' => 'The period is locked or submitted.'] + $extra, Response::HTTP_CONFLICT, $request);
        }

        return $this->responseFactory->json(['entry' => $entry->toArray() + ['locked' => false]], Response::HTTP_OK, $request);
    }

    /**
     * The `{ids}` or `{groupKey}` of an accept / dismiss request: `[ids, null]`, `[null, groupKey]`, or null when invalid.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: list<string>|null, 1: string|null}|null
     */
    private function selection(array $payload): ?array
    {
        $ids      = $payload['ids'] ?? null;
        $groupKey = $payload['groupKey'] ?? null;
        if (is_array($ids) && $groupKey === null && $ids !== [] && count($ids) <= CalendarSuggestionService::MAX_IDS && array_is_list($ids)) {
            foreach ($ids as $id) {
                if (!is_string($id) || $id === '' || strlen($id) > 36) {
                    return null;
                }
            }

            /* @var list<string> $ids */
            return [$ids, null];
        }
        if ($ids === null && is_string($groupKey) && $groupKey !== '' && strlen($groupKey) <= 48) {
            return [null, $groupKey];
        }

        return null;
    }

    /**
     * @param array<mixed> $keys
     */
    private function hasInvalidKey(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || mb_strlen($key) > 32) {
                return true;
            }
        }

        return false;
    }

    private function optionalTime(mixed $value, DateTimeImmutable $default): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? $default : Rfc3339::parse($value);
    }

    private function scope(ClientApiPrincipal $principal, ?string $requested): ?TimesheetScope
    {
        return $this->connections->scopeOf($principal->user, $requested);
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

    private function invalidSelection(Request $request): JsonResponse
    {
        return $this->responseFactory->error('invalid_request', 'Send ids (1-100 suggestion ids) or groupKey, an optional taskId and note.', Response::HTTP_BAD_REQUEST, $request);
    }

    private function tooManyRequests(Request $request, int $retryAfter): JsonResponse
    {
        return $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, ['Retry-After' => (string) $retryAfter]);
    }

    /**
     * GG-I1: personal data on these routes; nothing keeps a copy.
     */
    private function noStore(Response $response): Response
    {
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function notFound(Request $request): JsonResponse
    {
        return $this->responseFactory->error('not_found', 'Not found.', Response::HTTP_NOT_FOUND, $request);
    }
}
