<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiGuard;
use Nowo\TimeTrackBundle\Client\ClientApiPrincipal;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientIdempotencyStore;
use Nowo\TimeTrackBundle\Client\ClientResponseFactory;
use Nowo\TimeTrackBundle\Entity\MonitoringUploadIntent;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Monitoring\ActivityItem;
use Nowo\TimeTrackBundle\Monitoring\MonitoringAcknowledgement;
use Nowo\TimeTrackBundle\Monitoring\MonitoringContext;
use Nowo\TimeTrackBundle\Monitoring\MonitoringEvidenceStoreInterface;
use Nowo\TimeTrackBundle\Monitoring\MonitoringGate;
use Nowo\TimeTrackBundle\Monitoring\MonitoringImageInspector;
use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicy;
use Nowo\TimeTrackBundle\Monitoring\MonitoringSettings;
use Nowo\TimeTrackBundle\Monitoring\MonitoringUploadClaim;
use Nowo\TimeTrackBundle\Monitoring\MonitoringUploadSignerInterface;
use Nowo\TimeTrackBundle\Monitoring\ScreenshotUpload;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefs;
use Nowo\TimeTrackBundle\Repository\MonitoringUploadIntentRepositoryInterface;
use Nowo\TimeTrackBundle\Support\CanonicalJson;
use Nowo\TimeTrackBundle\Support\Rfc3339;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

use function array_is_list;
use function count;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function hash;
use function hash_equals;
use function hash_final;
use function hash_init;
use function hash_update;
use function hrtime;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_file;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function max;
use function mb_stripos;
use function preg_match;
use function preg_replace;
use function strlen;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Since 1.26.0 (contract §24.2–§24.3, spec 199): the activity-monitoring routes of the client API — acknowledge the
 * notice, ask for a screenshot upload (intent), upload it to the one-shot signed URL, mark a slot discarded, discard an
 * uploaded screenshot, and send activity aggregates. Bearer token with the `monitoring:write` scope (explicit grant).
 *
 * Every route answers 404 while monitoring is not configured ({@see MonitoringContext::isConfigured()}). Every refusal
 * check runs at intent time, before any byte is accepted ({@see MonitoringGate}); the PUT re-checks the policy and
 * enforces every cap while it streams the body to a temporary file that is deleted in `finally`. The image bytes never
 * sit in a property: this controller holds no per-request state (FrankenPHP worker mode, kernel reset false); intent
 * rows live in the bundle's entity manager and replays in the rate-limit cache pool.
 *
 * Every answer carries `Cache-Control: no-store`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff` and
 * `Vary: Authorization`. The access log line has the action, the status and the duration only.
 */
final readonly class TimeTrackMonitoringApiController
{
    /** `Idempotency-Key`: 1-64 visible ASCII characters (house rule). */
    public const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{1,64}$/D';

    /** Timer and entry ids. */
    public const ENTRY_ID_PATTERN = '/^[A-Za-z0-9-]{1,36}$/D';

    /** Screenshot ids the host store returns. */
    public const SCREENSHOT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** Upload ids (UUID). */
    public const UPLOAD_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** An upload stuck in `uploading` this long after its URL expired may be signed again. */
    private const STALE_UPLOAD_SECONDS = 300;

    /** Read size of the streamed PUT body. */
    private const CHUNK_BYTES = 65_536;

    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * Receives the client API collaborators and the monitoring services (null signer / intents / URL generator only
     * while monitoring is off).
     */
    public function __construct(
        private ClientApiGuard $guard,
        private ClientResponseFactory $responseFactory,
        private ClientApiRateLimiter $rateLimiter,
        private ClientIdempotencyStore $idempotency,
        private ClientApiAuditor $auditor,
        private MonitoringContext $context,
        private MonitoringGate $gate,
        private MonitoringSettings $settings,
        private ?MonitoringUploadSignerInterface $signer = null,
        private ?MonitoringUploadIntentRepositoryInterface $intents = null,
        private ?UrlGeneratorInterface $urlGenerator = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        private ?UserReferenceResolverInterface $userReferences = null,
    ) {
        $this->clock  = $clock ?? new NativeClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * POST monitoring/acknowledge `{policyVersion, noticeSha256, shownAt}` with an `Idempotency-Key`: 204; 409
     * `policy_changed {policyVersion}`; 422 `notice_mismatch`, `idempotency_mismatch`.
     */
    public function acknowledge(Request $request): Response
    {
        return $this->answer('client_monitoring_acknowledge', $request, fn (ClientApiPrincipal $principal): Response => $this->doAcknowledge($request, $principal));
    }

    /**
     * POST monitoring/screenshots/intent: every refusal check, then 201 (200 on a replay) with a one-shot signed URL.
     */
    public function screenshotIntent(Request $request): Response
    {
        return $this->answer('client_monitoring_intent', $request, fn (ClientApiPrincipal $principal): Response => $this->doIntent($request, $principal));
    }

    /**
     * PUT monitoring/uploads/{uploadId}?exp=&sig= with the raw image: 201 `{id}`; 403 `invalid_signature`; 413 / 415 /
     * 422 cap violations.
     */
    public function upload(Request $request, string $uploadId): Response
    {
        return $this->answer('client_monitoring_upload', $request, fn (ClientApiPrincipal $principal): Response => $this->doUpload($request, $principal, $uploadId));
    }

    /**
     * POST monitoring/screenshots/discarded `{entryId, slot, reason: "worker"}`: 204 (the slot is marked, nothing was
     * uploaded).
     */
    public function screenshotDiscarded(Request $request): Response
    {
        return $this->answer('client_monitoring_discarded', $request, fn (ClientApiPrincipal $principal): Response => $this->doDiscarded($request, $principal));
    }

    /**
     * DELETE monitoring/screenshots/{screenshotId}: 204, or 404 when it is not the worker's. Works whatever the policy
     * says now (the worker can always discard their own screenshot).
     */
    public function screenshotDelete(Request $request, string $screenshotId): Response
    {
        return $this->answer('client_monitoring_delete', $request, fn (ClientApiPrincipal $principal): Response => $this->doDelete($request, $principal, $screenshotId));
    }

    /**
     * POST monitoring/activity `{items: [...]}` with an `Idempotency-Key`: 202 `{accepted, dropped}`.
     */
    public function activity(Request $request): Response
    {
        return $this->answer('client_monitoring_activity', $request, fn (ClientApiPrincipal $principal): Response => $this->doActivity($request, $principal));
    }

    /**
     * Preflight, 404 while off, authentication and scope; then the action; then the privacy headers and the access log.
     *
     * @param callable(ClientApiPrincipal): Response $action
     */
    private function answer(string $logAction, Request $request, callable $action): Response
    {
        $started = hrtime(true);
        if ($request->isMethod('OPTIONS')) {
            $response = $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        } elseif (!$this->context->isConfigured()) {
            $response = $this->notFound($request);
        } else {
            $principal = $this->guard->authenticate($request, ClientScope::MonitoringWrite);
            $response  = $principal instanceof ClientApiPrincipal ? $action($principal) : $principal;
        }
        $this->logger->info('TimeTrack monitoring request.', [
            'bundle'      => 'time_track',
            'action'      => $logAction,
            'status'      => $response->getStatusCode(),
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        return $this->secure($response);
    }

    private function doAcknowledge(Request $request, ClientApiPrincipal $principal): Response
    {
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return $this->missingKey($request);
        }
        $payload  = $this->payload($request);
        $userId   = UserIdResolver::getId($principal->user);
        $bodyHash = hash('sha256', 'monitoring_acknowledge' . "\0" . CanonicalJson::encode($payload));
        $replay   = $this->idempotency->find($userId, 'monitoring_acknowledge:' . $key, $bodyHash);
        if ($replay === false) {
            return $this->idempotencyMismatch($request);
        }
        if ($replay !== null) {
            return $this->replay($replay, $request);
        }
        $version = $payload['policyVersion'] ?? null;
        $sha     = $payload['noticeSha256'] ?? null;
        if (!is_int($version) || $version < 1) {
            return $this->invalid($request, 'policyVersion');
        }
        if (!is_string($sha) || preg_match('/^[0-9a-fA-F]{64}$/D', $sha) !== 1) {
            return $this->invalid($request, 'noticeSha256');
        }
        if (!Rfc3339::parse($payload['shownAt'] ?? null) instanceof DateTimeImmutable) {
            return $this->invalid($request, 'shownAt');
        }
        try {
            $policy = $this->context->policyFor($principal->user);
            if (!$policy instanceof MonitoringPolicy || $policy->policyVersion !== $version) {
                $status = Response::HTTP_CONFLICT;
                $body   = ['error' => 'policy_changed', 'message' => 'The monitoring policy changed. Fetch GET me again.', 'policyVersion' => $policy?->policyVersion];
                $this->auditor->monitoringRefused('policy_changed', $principal->token, $request->getClientIp());
            } elseif (!hash_equals($policy->noticeSha256, strtolower($sha))) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                $body   = ['error' => 'notice_mismatch', 'message' => 'This is not the notice of the current policy version.'];
                $this->auditor->monitoringRefused('notice_mismatch', $principal->token, $request->getClientIp());
            } else {
                $this->context->acknowledge($principal->user, new MonitoringAcknowledgement($version, $this->clock->now(), $principal->token->getClientType()->value, strtolower($sha)));
                $this->auditor->record(ClientApiAuditAction::MonitoringAcknowledged, $principal->user, $principal->token->getCredentialId(), $principal->token->getClientType(), $request->getClientIp(), 'v' . $version);
                $status = Response::HTTP_NO_CONTENT;
                $body   = [];
            }
        } catch (Throwable $exception) {
            return $this->unavailable($request, $exception);
        }
        $this->idempotency->save($userId, 'monitoring_acknowledge:' . $key, $bodyHash, $status, $body);

        return $this->replay(['status' => $status, 'body' => $body], $request);
    }

    private function doIntent(Request $request, ClientApiPrincipal $principal): Response
    {
        $limited = $this->limit($principal, $request, 'monitoring_intent', $this->settings->intentsPer10Min);
        if ($limited instanceof Response) {
            return $limited;
        }
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return $this->missingKey($request);
        }
        $payload = $this->payload($request);
        $now     = $this->clock->now();
        $parsed  = $this->parseIntent($payload, $now, $request);
        if ($parsed instanceof Response) {
            return $parsed;
        }
        $userId          = UserIdResolver::getId($principal->user);
        $idempotencyHash = hash('sha256', $key);
        $bodyHash        = hash('sha256', 'monitoring_intent' . "\0" . CanonicalJson::encode($payload));

        try {
            $intents  = $this->intents();
            $existing = $intents->findByKey($userId, $idempotencyHash);
            if ($existing instanceof MonitoringUploadIntent && !hash_equals($existing->getBodyHash(), $bodyHash)) {
                return $this->idempotencyMismatch($request);
            }
            if ($existing instanceof MonitoringUploadIntent && $existing->getStatus() === MonitoringUploadIntent::STATUS_STORED) {
                return $this->responseFactory->json(['id' => $existing->getScreenshotId(), 'status' => 'stored'], Response::HTTP_OK, $request);
            }
            $refused = $this->intentRefusal($principal, $parsed, $existing?->getId() ?? '', $now, $request);
            if ($refused instanceof Response) {
                return $refused;
            }
            if ($existing instanceof MonitoringUploadIntent) {
                return $this->grant($principal, $this->resign($existing, $userId, $now), Response::HTTP_OK, $request);
            }
            $intents->purgeCreatedBefore($now->modify('-' . MonitoringSettings::INTENT_RETENTION_SECONDS . ' seconds'), $userId);
            $intent = (new MonitoringUploadIntent(
                $this->userReferences()->reference($principal->user),
                $idempotencyHash,
                $bodyHash,
                $parsed['entryId'],
                $parsed['slot'],
                $parsed['takenAt'],
                $parsed['policyVersion'],
                $parsed['blurred'],
                $parsed['mime'],
                $parsed['bytes'],
                $parsed['width'],
                $parsed['height'],
                $parsed['sha256'],
                $now,
            ))->signUntil($now->modify('+' . MonitoringSettings::URL_TTL_SECONDS . ' seconds'));
            try {
                $intents->save($intent);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request with the same key won: answer as its replay.
                $winner = $intents->findByKey($userId, $idempotencyHash);
                if (!$winner instanceof MonitoringUploadIntent || !hash_equals($winner->getBodyHash(), $bodyHash)) {
                    return $this->idempotencyMismatch($request);
                }

                return $this->grant($principal, $winner, Response::HTTP_OK, $request);
            }

            return $this->grant($principal, $intent, Response::HTTP_CREATED, $request);
        } catch (Throwable $exception) {
            return $this->unavailable($request, $exception);
        }
    }

    /**
     * The intent's answer when a check refuses it (409 / 422), null when every check passes.
     *
     * @param array{entryId: string, slot: DateTimeImmutable, takenAt: DateTimeImmutable, policyVersion: int, blurred: bool, mime: string, bytes: int, width: int, height: int, sha256: string} $intent
     */
    private function intentRefusal(ClientApiPrincipal $principal, array $intent, string $ownIntentId, DateTimeImmutable $now, Request $request): ?Response
    {
        $policy = $this->context->policyFor($principal->user);
        if (!$policy instanceof MonitoringPolicy) {
            return $this->refused($principal, $request, MonitoringGate::KIND_OFF);
        }
        // A capture of a newer version than the server's, or an unblurred capture of an older version the current
        // policy no longer allows unblurred: the client refetches GET me (and drops what the policy no longer allows).
        if ($intent['policyVersion'] > $policy->policyVersion || (!$intent['blurred'] && !$policy->fullResolution && $intent['policyVersion'] < $policy->policyVersion)) {
            return $this->policyChanged($principal, $request, $policy);
        }
        if (!$intent['blurred'] && !$policy->fullResolution) {
            return $this->invalid($request, 'blurred');
        }
        $slotEnd = $intent['slot']->getTimestamp() + $policy->slotMinutes * 60;
        if ($intent['takenAt']->getTimestamp() < $intent['slot']->getTimestamp() || $intent['takenAt']->getTimestamp() >= $slotEnd) {
            return $this->invalid($request, 'slot');
        }
        $reason = $this->gate->refusal($principal->user, $policy, $this->context->lastAcknowledged($principal->user), 'screenshots', $intent['entryId'], $intent['takenAt'], $now);
        if ($reason !== null) {
            return $this->refused($principal, $request, $reason);
        }
        if ($this->slotTaken($principal, $intent['entryId'], $intent['slot'], $ownIntentId, $now)) {
            $this->auditor->monitoringRefused('duplicate', $principal->token, $request->getClientIp());

            return $this->responseFactory->error('duplicate', 'This slot already has a screenshot.', Response::HTTP_CONFLICT, $request);
        }

        return null;
    }

    private function doUpload(Request $request, ClientApiPrincipal $principal, string $uploadId): Response
    {
        $limited = $this->limit($principal, $request, 'monitoring_upload', $this->settings->uploadsPer10Min);
        if ($limited instanceof Response) {
            return $limited;
        }
        $now    = $this->clock->now();
        $userId = UserIdResolver::getId($principal->user);
        $exp    = (string) $request->query->get('exp', '');
        $sig    = (string) $request->query->get('sig', '');
        try {
            $intent = preg_match(self::UPLOAD_ID_PATTERN, $uploadId) === 1 && preg_match('/^[1-9]\d{0,11}$/D', $exp) === 1 ? $this->intents()->findForUser($uploadId, $userId) : null;
            $until  = $intent?->getSignedUntil();
            if (!$intent instanceof MonitoringUploadIntent || !$until instanceof DateTimeImmutable || $until->getTimestamp() !== (int) $exp
                || !$this->signer()->verify($this->claim($principal, $intent, (int) $exp), $sig)
                || !$this->intents()->consume($intent->getId(), $until, $now)) {
                $this->auditor->monitoringRefused('invalid_signature', $principal->token, $request->getClientIp());

                return $this->responseFactory->error('invalid_signature', 'The upload URL is invalid, expired or already used. Ask for a new intent.', Response::HTTP_FORBIDDEN, $request);
            }
        } catch (Throwable $exception) {
            return $this->unavailable($request, $exception);
        }

        $stored = false;
        $path   = null;
        try {
            $refused = $this->uploadRefusal($principal, $intent, $request);
            if ($refused instanceof Response) {
                return $refused;
            }
            $path     = $this->temporaryFile();
            $streamed = $this->stream($request, $intent, $path);
            if ($streamed instanceof Response) {
                $this->auditor->monitoringRefused((string) (json_decode((string) $streamed->getContent(), true)['error'] ?? 'upload_mismatch'), $principal->token, $request->getClientIp());

                return $streamed;
            }
            if ($this->slotTaken($principal, $intent->getEntryId(), $intent->getSlot(), $intent->getId(), $now)) {
                return $this->responseFactory->error('duplicate', 'This slot already has a screenshot.', Response::HTTP_CONFLICT, $request);
            }
            $id = $this->store($principal, $intent, $path);
            $this->intents()->markStored($intent->getId(), $id);
            $stored = true;
            $this->auditor->record(ClientApiAuditAction::MonitoringUploaded, $principal->user, $principal->token->getCredentialId(), $principal->token->getClientType(), $request->getClientIp());

            return $this->responseFactory->json(['id' => $id], Response::HTTP_CREATED, $request);
        } catch (Throwable $exception) {
            return $this->unavailable($request, $exception);
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            if (!$stored) {
                $this->releaseQuietly($intent->getId());
            }
        }
    }

    /**
     * The policy re-check of a PUT (409 / 422), then the request headers (415 / 413 / 422); null when the body may be
     * read.
     */
    private function uploadRefusal(ClientApiPrincipal $principal, MonitoringUploadIntent $intent, Request $request): ?Response
    {
        $policy = $this->context->policyFor($principal->user);
        if (!$policy instanceof MonitoringPolicy) {
            return $this->refused($principal, $request, MonitoringGate::KIND_OFF);
        }
        if ($intent->getPolicyVersion() > $policy->policyVersion || (!$intent->isBlurred() && !$policy->fullResolution)) {
            return $this->policyChanged($principal, $request, $policy);
        }
        if (!$policy->allows('screenshots')) {
            return $this->refused($principal, $request, MonitoringGate::KIND_OFF);
        }
        if (!MonitoringGate::isAcknowledged($policy, $this->context->lastAcknowledged($principal->user))) {
            return $this->refused($principal, $request, MonitoringGate::NOT_ACKNOWLEDGED);
        }
        $type = strtolower(trim((string) preg_replace('/;.*$/s', '', (string) $request->headers->get('Content-Type', ''))));
        if ($type !== $intent->getMime()) {
            return $this->responseFactory->error('unsupported_media_type', 'Content-Type must be the declared image/webp or image/jpeg.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $request);
        }
        $length = (string) $request->headers->get('Content-Length', '');
        if (preg_match('/^\d{1,12}$/D', $length) === 1 && (int) $length > MonitoringSettings::MAX_BYTES) {
            return $this->tooLarge($request);
        }
        if (preg_match('/^\d{1,12}$/D', $length) !== 1 || (int) $length !== $intent->getBytes()) {
            return $this->mismatch($request, 'bytes');
        }

        return null;
    }

    /**
     * Streams the body into the temporary file, enforcing the size, the hash, the magic bytes and the header
     * dimensions; the error answer, or null when the file is the declared image. The bytes only pass through local
     * variables of this call.
     */
    private function stream(Request $request, MonitoringUploadIntent $intent, string $path): ?Response
    {
        $input  = $request->getContent(true);
        $output = fopen($path, 'w');
        if (!is_resource($output)) {
            throw new RuntimeException('The temporary upload file cannot be opened.'); // @codeCoverageIgnore
        }
        $context = hash_init('sha256');
        $size    = 0;
        $head    = '';
        try {
            while (!feof($input) && is_string($chunk = fread($input, self::CHUNK_BYTES)) && $chunk !== '') {
                $size += strlen($chunk);
                if ($size > $intent->getBytes()) {
                    return $size > MonitoringSettings::MAX_BYTES ? $this->tooLarge($request) : $this->mismatch($request, 'bytes');
                }
                if (strlen($head) < 16) {
                    $head .= substr($chunk, 0, 16 - strlen($head));
                }
                hash_update($context, $chunk);
                fwrite($output, $chunk);
            }
        } finally {
            fclose($output);
            fclose($input);
        }
        if ($size !== $intent->getBytes()) {
            return $this->mismatch($request, 'bytes');
        }
        if (!hash_equals($intent->getSha256(), hash_final($context))) {
            return $this->mismatch($request, 'sha256');
        }
        $sniffed = MonitoringImageInspector::sniff($head);
        if ($sniffed === null) {
            return $this->responseFactory->error('unsupported_media_type', 'The image is neither WebP nor JPEG.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $request);
        }
        if ($sniffed !== $intent->getMime()) {
            return $this->mismatch($request, 'mime');
        }
        $dimensions = MonitoringImageInspector::dimensions($path);
        if ($dimensions === null || $dimensions[2] !== $sniffed) {
            return $this->responseFactory->error('unsupported_media_type', 'The image header cannot be read.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $request);
        }
        if ($dimensions[0] !== $intent->getWidth() || $dimensions[1] !== $intent->getHeight()) {
            return $this->mismatch($request, 'dimensions');
        }

        return null;
    }

    /**
     * Hands the checked file to the host store as a read-only stream; returns the host's id.
     */
    private function store(ClientApiPrincipal $principal, MonitoringUploadIntent $intent, string $path): string
    {
        $stream = fopen($path, 'r');
        if (!is_resource($stream)) {
            throw new RuntimeException('The temporary upload file cannot be read.'); // @codeCoverageIgnore
        }
        try {
            $id = $this->evidence()->storeScreenshot(new ScreenshotUpload(
                TimeTrackUserRefs::fromUser($principal->user),
                $intent->getEntryId(),
                $intent->getSlot(),
                $intent->getTakenAt(),
                $intent->getPolicyVersion(),
                $intent->isBlurred(),
                $intent->getMime(),
                $intent->getBytes(),
                $intent->getWidth(),
                $intent->getHeight(),
                $intent->getSha256(),
                $stream,
            ));
        } finally {
            fclose($stream);
        }
        if (preg_match(self::SCREENSHOT_ID_PATTERN, $id) !== 1) {
            throw new RuntimeException('The evidence store returned an id outside [A-Za-z0-9_-]{1,64}.');
        }

        return $id;
    }

    private function doDiscarded(Request $request, ClientApiPrincipal $principal): Response
    {
        $limited = $this->limit($principal, $request, 'monitoring_discarded', $this->settings->intentsPer10Min);
        if ($limited instanceof Response) {
            return $limited;
        }
        $payload = $this->payload($request);
        $entryId = $payload['entryId'] ?? null;
        $slot    = Rfc3339::parse($payload['slot'] ?? null);
        $now     = $this->clock->now();
        if (!is_string($entryId) || preg_match(self::ENTRY_ID_PATTERN, $entryId) !== 1) {
            return $this->invalid($request, 'entryId');
        }
        if (!$slot instanceof DateTimeImmutable || $slot->getTimestamp() > $now->getTimestamp() + MonitoringSettings::CLOCK_SKEW_SECONDS) {
            return $this->invalid($request, 'slot');
        }
        if (($payload['reason'] ?? null) !== 'worker') {
            return $this->invalid($request, 'reason');
        }
        try {
            $policy = $this->context->policyFor($principal->user);
            if (!$policy instanceof MonitoringPolicy) {
                return $this->refused($principal, $request, MonitoringGate::KIND_OFF);
            }
            $reason = $this->gate->refusal($principal->user, $policy, null, 'screenshots', $entryId, $slot, $now, $policy->slotMinutes * 60, false, false);
            if ($reason !== null) {
                return $this->refused($principal, $request, $reason);
            }
            $this->evidence()->markDiscarded(TimeTrackUserRefs::fromUser($principal->user), $entryId, $slot);
        } catch (Throwable $exception) {
            return $this->unavailable($request, $exception);
        }

        return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
    }

    private function doDelete(Request $request, ClientApiPrincipal $principal, string $screenshotId): Response
    {
        $limited = $this->limit($principal, $request, 'monitoring_delete', $this->settings->intentsPer10Min);
        if ($limited instanceof Response) {
            return $limited;
        }
        // The route requirement already restricts {screenshotId} to SCREENSHOT_ID_PATTERN.
        try {
            $discarded = $this->evidence()->discard(TimeTrackUserRefs::fromUser($principal->user), $screenshotId);
        } catch (Throwable $exception) {
            return $this->unavailable($request, $exception);
        }

        return $discarded ? $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request) : $this->notFound($request);
    }

    private function doActivity(Request $request, ClientApiPrincipal $principal): Response
    {
        $limited = $this->limit($principal, $request, 'monitoring_activity', $this->settings->activityPer10Min);
        if ($limited instanceof Response) {
            return $limited;
        }
        $key = $this->idempotencyKey($request);
        if ($key === null) {
            return $this->missingKey($request);
        }
        $payload  = $this->payload($request);
        $userId   = UserIdResolver::getId($principal->user);
        $bodyHash = hash('sha256', 'monitoring_activity' . "\0" . CanonicalJson::encode($payload));
        $replay   = $this->idempotency->find($userId, 'monitoring_activity:' . $key, $bodyHash);
        if ($replay === false) {
            return $this->idempotencyMismatch($request);
        }
        if ($replay !== null) {
            return $this->replay($replay, $request);
        }
        $now   = $this->clock->now();
        $items = $this->parseActivity($payload, $now);
        if ($items === null) {
            return $this->invalid($request, 'items');
        }
        $accepted = [];
        try {
            $policy = $this->context->policyFor($principal->user);
            if (!$policy instanceof MonitoringPolicy || !$policy->isCollecting()) {
                return $this->refused($principal, $request, MonitoringGate::KIND_OFF);
            }
            $last = $this->context->lastAcknowledged($principal->user);
            if (!MonitoringGate::isAcknowledged($policy, $last)) {
                return $this->refused($principal, $request, MonitoringGate::NOT_ACKNOWLEDGED);
            }
            foreach ($items as $item) {
                if (!$policy->allows($item->policyKind())) {
                    return $this->refused($principal, $request, MonitoringGate::KIND_OFF);
                }
            }
            foreach ($items as $item) {
                if (!$this->excluded($item, $policy) && $this->gate->refusal($principal->user, $policy, $last, $item->policyKind(), $item->entryId, $item->slot, $now, $policy->slotMinutes * 60) === null) {
                    $accepted[] = $item;
                }
            }
            if ($accepted !== []) {
                $this->evidence()->storeActivity(TimeTrackUserRefs::fromUser($principal->user), $accepted);
            }
        } catch (Throwable $exception) {
            return $this->unavailable($request, $exception);
        }
        $body = ['accepted' => count($accepted), 'dropped' => count($items) - count($accepted)];
        $this->idempotency->save($userId, 'monitoring_activity:' . $key, $bodyHash, Response::HTTP_ACCEPTED, $body);

        return $this->responseFactory->json($body, Response::HTTP_ACCEPTED, $request);
    }

    /**
     * The checked fields of an intent, or the 400 / 413 / 415 answer.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{entryId: string, slot: DateTimeImmutable, takenAt: DateTimeImmutable, policyVersion: int, blurred: bool, mime: string, bytes: int, width: int, height: int, sha256: string}|Response
     */
    private function parseIntent(array $payload, DateTimeImmutable $now, Request $request): array|Response
    {
        $entryId = $payload['entryId'] ?? null;
        $slot    = Rfc3339::parse($payload['slot'] ?? null);
        $takenAt = Rfc3339::parse($payload['takenAt'] ?? null);
        $version = $payload['policyVersion'] ?? null;
        $blurred = $payload['blurred'] ?? null;
        $mime    = $payload['mime'] ?? null;
        $bytes   = $payload['bytes'] ?? null;
        $width   = $payload['width'] ?? null;
        $height  = $payload['height'] ?? null;
        $sha256  = $payload['sha256'] ?? null;
        $field   = match (true) {
            !is_string($entryId) || preg_match(self::ENTRY_ID_PATTERN, $entryId) !== 1                                                         => 'entryId',
            !$slot instanceof DateTimeImmutable                                                                                                => 'slot',
            !$takenAt instanceof DateTimeImmutable || $takenAt->getTimestamp() > $now->getTimestamp() + MonitoringSettings::CLOCK_SKEW_SECONDS => 'takenAt',
            !is_int($version) || $version < 1                                                                                                  => 'policyVersion',
            !is_bool($blurred)                                                                                                                 => 'blurred',
            !is_string($mime)                                                                                                                  => 'mime',
            !is_int($bytes) || $bytes < 1                                                                                                      => 'bytes',
            !is_int($width) || $width < 1 || $width > MonitoringPolicy::MAX_LONG_SIDE_PX                                                       => 'width',
            !is_int($height) || $height < 1 || $height > MonitoringPolicy::MAX_LONG_SIDE_PX                                                    => 'height',
            !is_string($sha256) || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1                                                              => 'sha256',
            default                                                                                                                            => null,
        };
        if ($field !== null) {
            return $this->invalid($request, $field);
        }
        /* @var string $entryId */
        /* @var DateTimeImmutable $slot */
        /* @var DateTimeImmutable $takenAt */
        /* @var int $version */
        /* @var bool $blurred */
        /** @var string $mime */
        /* @var int $bytes */
        /* @var int $width */
        /* @var int $height */
        /* @var string $sha256 */
        if (!in_array($mime, MonitoringImageInspector::MIMES, true)) {
            return $this->responseFactory->error('unsupported_media_type', 'Only image/webp and image/jpeg are accepted.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $request);
        }
        if ($bytes > MonitoringSettings::MAX_BYTES) {
            return $this->tooLarge($request);
        }

        return ['entryId' => $entryId, 'slot' => $slot, 'takenAt' => $takenAt, 'policyVersion' => $version, 'blurred' => $blurred, 'mime' => $mime, 'bytes' => $bytes, 'width' => $width, 'height' => $height, 'sha256' => $sha256];
    }

    /**
     * The items of an activity batch (1-200, names redacted), or null when one is invalid.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<ActivityItem>|null
     */
    private function parseActivity(array $payload, DateTimeImmutable $now): ?array
    {
        $raw = $payload['items'] ?? null;
        if (!is_array($raw) || $raw === [] || !array_is_list($raw) || count($raw) > MonitoringSettings::MAX_ACTIVITY_ITEMS) {
            return null;
        }
        $items = [];
        foreach ($raw as $value) {
            $entryId = is_array($value) ? ($value['entryId'] ?? null) : null;
            $slot    = is_array($value) ? Rfc3339::parse($value['slot'] ?? null) : null;
            $kind    = is_array($value) ? ($value['kind'] ?? null) : null;
            $name    = is_array($value) ? ($value['name'] ?? null) : null;
            $seconds = is_array($value) ? ($value['seconds'] ?? null) : null;
            $level   = is_array($value) ? ($value['level'] ?? null) : null;
            if (!is_string($entryId) || preg_match(self::ENTRY_ID_PATTERN, $entryId) !== 1 || !$slot instanceof DateTimeImmutable || $slot->getTimestamp() > $now->getTimestamp() + MonitoringSettings::CLOCK_SKEW_SECONDS
                || !is_string($kind) || ($name !== null && !is_string($name)) || !is_int($seconds) || ($level !== null && !is_int($level))) {
                return null;
            }
            try {
                $items[] = new ActivityItem($entryId, $slot, $kind, $name === null ? null : self::redact($name), $seconds, $level);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return $items;
    }

    /**
     * App names as stored: e-mail addresses and runs of four or more digits replaced (checked again after the client).
     */
    public static function redact(string $name): string
    {
        $name = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[email]', $name);

        return trim((string) preg_replace('/\d{4,}/', '[digits]', $name));
    }

    /**
     * Whether an app item names an excluded app (case-insensitive substring): such items are dropped, never stored.
     */
    private function excluded(ActivityItem $item, MonitoringPolicy $policy): bool
    {
        foreach ($item->name === null ? [] : $policy->excludedApps as $app) {
            if (mb_stripos((string) $item->name, $app) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The intent's URL answer (201 new, 200 replay).
     */
    private function grant(ClientApiPrincipal $principal, MonitoringUploadIntent $intent, int $status, Request $request): JsonResponse
    {
        $until = $intent->getSignedUntil() ?? $this->clock->now();
        $exp   = $until->getTimestamp();
        $url   = $this->urlGenerator()->generate($this->settings->uploadRoute, [
            'uploadId' => $intent->getId(),
            'exp'      => $exp,
            'sig'      => $this->signer()->sign($this->claim($principal, $intent, $exp)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->responseFactory->json([
            'uploadId'  => $intent->getId(),
            'url'       => $url,
            'method'    => 'PUT',
            'headers'   => ['Content-Type' => $intent->getMime()],
            'expiresAt' => MonitoringContext::utc($until),
        ], $status, $request);
    }

    /**
     * A replayed pending intent keeps its URL while it is valid; otherwise it gets a new one that expires later than
     * any URL signed before, so a dead URL never becomes valid again (fresh read either way).
     */
    private function resign(MonitoringUploadIntent $intent, string $userId, DateTimeImmutable $now): MonitoringUploadIntent
    {
        $until = $intent->getSignedUntil();
        if ($intent->getStatus() === MonitoringUploadIntent::STATUS_PENDING && $until instanceof DateTimeImmutable && $until->getTimestamp() > $now->getTimestamp()) {
            return $intent;
        }
        $next = max($now->getTimestamp() + MonitoringSettings::URL_TTL_SECONDS, ($until?->getTimestamp() ?? 0) + 1);
        $this->intents()->sign($intent->getId(), $now->setTimestamp($next), $now->modify('-' . self::STALE_UPLOAD_SECONDS . ' seconds'));

        return $this->intents()->findForUser($intent->getId(), $userId) ?? $intent;
    }

    private function claim(ClientApiPrincipal $principal, MonitoringUploadIntent $intent, int $exp): MonitoringUploadClaim
    {
        return new MonitoringUploadClaim($intent->getId(), $principal->token->getTokenHash(), $intent->getSha256(), $intent->getBytes(), $intent->getMime(), $exp);
    }

    private function slotTaken(ClientApiPrincipal $principal, string $entryId, DateTimeImmutable $slot, string $ownIntentId, DateTimeImmutable $now): bool
    {
        return $this->evidence()->hasScreenshot(TimeTrackUserRefs::fromUser($principal->user), $entryId, $slot)
            || $this->intents()->slotTaken(UserIdResolver::getId($principal->user), $entryId, $slot, $ownIntentId, $now);
    }

    private function releaseQuietly(string $id): void
    {
        try {
            $this->intents()->release($id);
        } catch (Throwable $exception) {
            $this->logger->warning('TimeTrack monitoring upload not released.', ['bundle' => 'time_track', 'action' => 'client_monitoring_upload', 'exception' => $exception::class]);
        }
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tt_monitoring_');
        if ($path === false) {
            throw new RuntimeException('No temporary upload file.'); // @codeCoverageIgnore
        }

        return $path;
    }

    /**
     * Per-token limit of a monitoring bucket (10-minute window); null when allowed.
     */
    private function limit(ClientApiPrincipal $principal, Request $request, string $bucket, int $limit): ?JsonResponse
    {
        $retry = $this->rateLimiter->hit($bucket, $principal->token->getTokenHash(), $limit, MonitoringSettings::WINDOW_SECONDS);

        return $retry > 0 ? $this->responseFactory->error('rate_limited', 'Too many requests. Try again later.', Response::HTTP_TOO_MANY_REQUESTS, $request, ['Retry-After' => (string) $retry]) : null;
    }

    private function refused(ClientApiPrincipal $principal, Request $request, string $reason): JsonResponse
    {
        $this->auditor->monitoringRefused($reason, $principal->token, $request->getClientIp());

        return $this->responseFactory->json(['error' => 'monitoring_refused', 'message' => 'Monitoring refused this item.', 'reason' => $reason], Response::HTTP_UNPROCESSABLE_ENTITY, $request);
    }

    private function policyChanged(ClientApiPrincipal $principal, Request $request, MonitoringPolicy $policy): JsonResponse
    {
        $this->auditor->monitoringRefused('policy_changed', $principal->token, $request->getClientIp());

        return $this->responseFactory->json(['error' => 'policy_changed', 'message' => 'The monitoring policy changed. Fetch GET me again.', 'policyVersion' => $policy->policyVersion], Response::HTTP_CONFLICT, $request);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');

        return preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) === 1 ? $key : null;
    }

    /**
     * @param array{status: int, body: array<string, mixed>} $replay
     */
    private function replay(array $replay, Request $request): Response
    {
        return $replay['status'] === Response::HTTP_NO_CONTENT ? $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request) : $this->responseFactory->json($replay['body'], $replay['status'], $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) && !array_is_list($decoded) ? $decoded : [];

        return $payload;
    }

    private function invalid(Request $request, string $field): JsonResponse
    {
        return $this->responseFactory->json(['error' => 'invalid_request', 'message' => 'Missing or invalid ' . $field . '.', 'field' => $field], Response::HTTP_BAD_REQUEST, $request);
    }

    private function mismatch(Request $request, string $field): JsonResponse
    {
        return $this->responseFactory->json(['error' => 'upload_mismatch', 'message' => 'The upload does not match the intent (' . $field . ').', 'field' => $field], Response::HTTP_UNPROCESSABLE_ENTITY, $request);
    }

    private function tooLarge(Request $request): JsonResponse
    {
        return $this->responseFactory->error('too_large', 'A screenshot is at most 1.5 MiB (1572864 bytes).', Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $request);
    }

    private function missingKey(Request $request): JsonResponse
    {
        return $this->responseFactory->error('invalid_request', 'The Idempotency-Key header (1-64 visible characters) is required.', Response::HTTP_BAD_REQUEST, $request);
    }

    private function idempotencyMismatch(Request $request): JsonResponse
    {
        return $this->responseFactory->error('idempotency_mismatch', 'This Idempotency-Key was used with another request.', Response::HTTP_UNPROCESSABLE_ENTITY, $request);
    }

    private function unavailable(Request $request, Throwable $exception): JsonResponse
    {
        $this->logger->warning('TimeTrack monitoring port failed.', ['bundle' => 'time_track', 'action' => 'client_monitoring', 'exception' => $exception::class]);

        return $this->responseFactory->error('monitoring_unavailable', 'Monitoring is unavailable. Try again later.', Response::HTTP_SERVICE_UNAVAILABLE, $request, ['Retry-After' => '60']);
    }

    private function notFound(Request $request): JsonResponse
    {
        return $this->responseFactory->error('not_found', 'Not found.', Response::HTTP_NOT_FOUND, $request);
    }

    /**
     * The privacy headers of every answer; a 429 also gets `retryAfter` in the body.
     */
    private function secure(Response $response): Response
    {
        $headers = $response->headers;
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('Cache-Control', 'no-store');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('Pragma', 'no-cache');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('X-Content-Type-Options', 'nosniff');
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $headers->set('Vary', implode(', ', [...$headers->all('vary'), 'Authorization']));
        if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS && $response instanceof JsonResponse) {
            $retry = max(1, (int) $headers->get('Retry-After', '60'));
            $body  = json_decode((string) $response->getContent(), true);
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $headers->set('Retry-After', (string) $retry);
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $response->setData((is_array($body) ? $body : []) + ['retryAfter' => $retry]);
        }

        return $response;
    }

    private function evidence(): MonitoringEvidenceStoreInterface
    {
        return $this->context->evidence() ?? throw new RuntimeException('Monitoring is not configured.');
    }

    private function intents(): MonitoringUploadIntentRepositoryInterface
    {
        return $this->intents ?? throw new RuntimeException('Monitoring is not configured.');
    }

    private function signer(): MonitoringUploadSignerInterface
    {
        return $this->signer ?? throw new RuntimeException('Monitoring is not configured.');
    }

    private function userReferences(): UserReferenceResolverInterface
    {
        return $this->userReferences ?? throw new RuntimeException('Monitoring is not configured.');
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        return $this->urlGenerator ?? throw new RuntimeException('Monitoring is not configured.');
    }
}
