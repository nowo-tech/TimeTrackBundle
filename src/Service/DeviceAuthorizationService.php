<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateInterval;
use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientAccountChecker;
use Nowo\TimeTrackBundle\Client\ClientApiAuditor;
use Nowo\TimeTrackBundle\Client\ClientApiRateLimiter;
use Nowo\TimeTrackBundle\Client\ClientCredentialIssuerInterface;
use Nowo\TimeTrackBundle\Dto\DeviceCodeGrant;
use Nowo\TimeTrackBundle\Dto\DevicePollResult;
use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;
use Nowo\TimeTrackBundle\Enum\ClientApiAuditAction;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;
use Nowo\TimeTrackBundle\Exception\DeviceAuthorizationNotPendingException;
use Nowo\TimeTrackBundle\Exception\DeviceCodeAttemptsExceededException;
use Nowo\TimeTrackBundle\Exception\DeviceFlowCapacityExceededException;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\DeviceAuthorizationRepositoryInterface;
use Nowo\TimeTrackBundle\Repository\DoctrineOrmDeviceAuthorizationRepository;
use Nowo\TimeTrackBundle\Support\IpPrefix;
use Nowo\TimeTrackBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;

use function base64_encode;
use function hash;
use function max;
use function mb_substr;
use function preg_replace;
use function random_bytes;
use function random_int;
use function rtrim;
use function strlen;
use function strtoupper;
use function strtr;
use function substr;
use function trim;

/**
 * Device flow (contract v0.2 §2, RFC 8628 shape): issues device / user codes, lets the signed-in user look up, approve
 * or deny a request (host page or the bundle's optional default page), and exchanges an approved device code for a
 * session token once.
 *
 * - Device code: 32 random bytes (base64url); user code: 8 characters from a 20-letter alphabet without vowels
 *   (shown `XXXX-XXXX`). Only SHA-256 hashes are stored.
 * - Lookups by user code are limited per user (`lookup_limit` per `lookup_window_seconds`).
 * - Approval asks the host for a credential ({@see ClientCredentialIssuerInterface}), so the device shows in the host's
 *   credential list and is revoked like the others.
 * - CSRF: forms that approve or deny use {@see self::csrfTokenId()} (bound to the action and the request id).
 */
final readonly class DeviceAuthorizationService
{
    private UserReferenceResolverInterface $userReferences;

    /** Base-20 alphabet without vowels (no words, no 0/O 1/I confusion). */
    public const USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    public const USER_CODE_LENGTH = 8;

    public const CLIENT_NAME_MAX_LENGTH = 100;

    /** Retry-After (seconds) when the global cap of unexpired requests is reached. */
    public const CAPACITY_RETRY_AFTER = 60;

    private ClockInterface $clock;

    /**
     * @param int $codeTtlSeconds Lifetime of a request (`expiresIn`)
     * @param int $intervalSeconds Minimum polling interval (`interval`; faster polls get `slow_down`)
     * @param int $lookupLimit User code lookups per user per window
     * @param int $lookupWindowSeconds Window of the lookup limit
     * @param int $maxPendingPerPrefix Requests created per client IPv4 /24 or IPv6 /64 within the code TTL (0 = off)
     * @param int $maxPending Unexpired requests in total (0 = off)
     * @param ClientAccountChecker|null $accountChecker Since 1.29: refuses the exchange of a refused account (`access_denied`)
     */
    public function __construct(
        private DeviceAuthorizationRepositoryInterface $repository,
        private ClientCredentialIssuerInterface $credentialIssuer,
        private ClientAuthService $authService,
        private ClientApiAuditor $auditor,
        private ClientApiRateLimiter $rateLimiter,
        private int $codeTtlSeconds = 600,
        private int $intervalSeconds = 5,
        private int $lookupLimit = 10,
        private int $lookupWindowSeconds = 900,
        ?ClockInterface $clock = null,
        private int $maxPendingPerPrefix = 20,
        private int $maxPending = 10000,
        ?UserReferenceResolverInterface $userReferences = null,
        private ?ClientAccountChecker $accountChecker = null,
    ) {
        $this->clock          = $clock ?? new NativeClock();
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
    }

    /**
     * Creates a pending request. Bounded per client IP prefix and in total, so an unauthenticated client cannot fill
     * the table by rotating addresses. With the global cap on, expired rows are purged before counting.
     *
     * @param string $clientName Name the client sent (control characters removed, trimmed, capped at 100 characters)
     * @param list<ClientScope>|null $scopes Requested scopes (null = the default scopes; parse device input with {@see ClientScope::fromRequested()})
     * @param string|null $clientIp Device IP (its /24 or /64 is counted and shown on the approval page)
     *
     * @throws DeviceFlowCapacityExceededException When a cap is reached
     */
    public function start(ClientType $clientType, string $clientName, ?array $scopes = null, ?string $clientIp = null): DeviceCodeGrant
    {
        $ttl      = max(60, $this->codeTtlSeconds);
        $ipPrefix = IpPrefix::of($clientIp);
        $this->checkCapacity($ipPrefix, $ttl);

        $deviceCode = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $userCode   = $this->newUserCode();
        $now        = $this->clock->now();

        $this->repository->save(new DeviceAuthorization(
            self::hash($deviceCode),
            self::hash($userCode),
            $clientType,
            $this->cleanClientName($clientName, $clientType),
            ClientScope::normalize($scopes),
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $now->add(new DateInterval('PT' . $ttl . 'S')),
            $now,
            $ipPrefix,
        ));

        return new DeviceCodeGrant($deviceCode, self::formatUserCode($userCode), $ttl, max(1, $this->intervalSeconds));
    }

    /**
     * Pending request of a user code typed by the signed-in user (null when unknown, expired or already decided).
     *
     * @throws DeviceCodeAttemptsExceededException When the user used up the lookup budget
     */
    public function findPending(string $userCode, UserInterface $user): ?DeviceAuthorization
    {
        $retry = $this->rateLimiter->hit('device_lookup', UserIdResolver::getId($user), $this->lookupLimit, $this->lookupWindowSeconds);
        if ($retry > 0) {
            throw new DeviceCodeAttemptsExceededException($retry);
        }

        $normalized = self::normalizeUserCode($userCode);
        if (strlen($normalized) !== self::USER_CODE_LENGTH) {
            return null;
        }

        return $this->repository->findPendingByUserCodeHash(self::hash($normalized));
    }

    /**
     * Pending request by id (the approve / deny forms post it; check the CSRF token first).
     */
    public function findPendingById(string $id): ?DeviceAuthorization
    {
        return $id === '' ? null : $this->repository->findPendingById($id);
    }

    /**
     * Approves a pending request: the host issues a credential, the device may exchange its code once.
     *
     * Since 1.27.0 (Beacon spec 239) the request is locked and read again first (the bundle's Doctrine store): a second
     * approval (a double submit, another tab) or an approval racing a denial or the expiry finds it no longer pending
     * and issues nothing. Up to 1.26 each approval issued a credential, and the later one overwrote the earlier one's
     * id, so a credential stayed issued with no device ever receiving it.
     *
     * @throws DeviceAuthorizationNotPendingException When the request was decided, exchanged or expired meanwhile (nothing issued)
     *
     * @return string The credential id the host issued
     */
    public function approve(DeviceAuthorization $authorization, UserInterface $user, ?string $clientIp = null): string
    {
        // @igor-ignore - the closure runs once inside this call, it is not stored.
        [$current, $credentialId] = $this->decide($authorization, function (DeviceAuthorization $current) use ($user): string {
            $credentialId = $this->credentialIssuer->issueForDevice($user, $current->getClientType(), $current->getClientName(), $current->getScopes());
            $current->approve($this->userReferences->reference($user), $credentialId);

            return $credentialId;
        });
        $this->auditor->record(ClientApiAuditAction::DeviceApproved, $user, $credentialId, $current->getClientType(), $clientIp);

        return $credentialId;
    }

    /**
     * Denies a pending request. Since 1.27.0 under the request's lock, like {@see self::approve()}.
     *
     * @throws DeviceAuthorizationNotPendingException When the request was decided, exchanged or expired meanwhile (nothing changed)
     */
    public function deny(DeviceAuthorization $authorization, UserInterface $user, ?string $clientIp = null): void
    {
        // @igor-ignore - the closure runs once inside this call, it is not stored.
        [$current] = $this->decide($authorization, function (DeviceAuthorization $current) use ($user): null {
            $current->deny($this->userReferences->reference($user));

            return null;
        });
        $this->auditor->record(ClientApiAuditAction::DeviceDenied, $user, null, $current->getClientType(), $clientIp);
    }

    /**
     * Since 1.27.0: one decision on the request as read under its row lock (stores with the bundle's Doctrine store; as
     * given otherwise), refused unless still pending and unexpired; the audit runs after the commit.
     *
     * @template T
     *
     * @param callable(DeviceAuthorization): T $decision
     *
     * @throws DeviceAuthorizationNotPendingException
     *
     * @return array{0: DeviceAuthorization, 1: T}
     */
    private function decide(DeviceAuthorization $authorization, callable $decision): array
    {
        $repository = $this->repository;
        $unit       = function () use ($repository, $authorization, $decision): array {
            $current = $repository instanceof DoctrineOrmDeviceAuthorizationRepository ? $repository->lockById($authorization->getId()) : $authorization;
            if (!$current instanceof DeviceAuthorization || $current->getStatus() !== DeviceAuthorizationStatus::Pending || $current->isExpired($this->clock->now())) {
                throw new DeviceAuthorizationNotPendingException();
            }
            $result = $decision($current);
            $repository->save($current);

            return [$current, $result];
        };

        return $repository instanceof DoctrineOrmDeviceAuthorizationRepository ? $repository->runner()->run($unit) : $unit();
    }

    /**
     * Token exchange of the device (`POST device/token`).
     */
    public function poll(string $deviceCode, ?string $clientIp = null): DevicePollResult
    {
        $authorization = $deviceCode === '' ? null : $this->repository->findByDeviceCodeHash(self::hash($deviceCode));
        $now           = $this->clock->now();
        if (!$authorization instanceof DeviceAuthorization || $authorization->getStatus() === DeviceAuthorizationStatus::Consumed) {
            return DevicePollResult::error(DevicePollResult::EXPIRED);
        }

        if ($authorization->isExpired($now)) {
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $this->repository->remove($authorization);

            return DevicePollResult::error(DevicePollResult::EXPIRED);
        }

        return match ($authorization->getStatus()) {
            DeviceAuthorizationStatus::Denied   => $this->consumeDenied($authorization),
            DeviceAuthorizationStatus::Approved => $this->exchange($authorization, $clientIp),
            default                             => $this->pending($authorization),
        };
    }

    /**
     * CSRF token id of the approve / deny form of a request (`nowo_time_track_device/<action>/<id>`).
     *
     * @param string $action `approve` or `deny`
     */
    public function csrfTokenId(string $action, DeviceAuthorization $authorization): string
    {
        return 'nowo_time_track_device/' . $action . '/' . $authorization->getId();
    }

    /**
     * Upper-case letters and digits of a typed user code (dashes and spaces removed).
     */
    public static function normalizeUserCode(string $userCode): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($userCode));
    }

    /**
     * `XXXX-XXXX` display form of a normalised user code.
     */
    public static function formatUserCode(string $userCode): string
    {
        return substr($userCode, 0, 4) . '-' . substr($userCode, 4);
    }

    /**
     * SHA-256 of a code.
     */
    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    private function cleanClientName(string $clientName, ClientType $clientType): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clientName));

        return $clean === '' ? $clientType->value : mb_substr($clean, 0, self::CLIENT_NAME_MAX_LENGTH);
    }

    private function checkCapacity(?string $ipPrefix, int $ttl): void
    {
        $retry = $this->rateLimiter->hit('device_prefix', $ipPrefix ?? 'none', $this->maxPendingPerPrefix, $ttl);
        if ($retry > 0) {
            throw new DeviceFlowCapacityExceededException($retry);
        }

        if ($this->maxPending <= 0) {
            return;
        }

        // Expired rows are useless (polling one answers expired_token like an unknown code): drop them first.
        $this->repository->purgeExpired();
        if ($this->repository->countUnexpired() >= $this->maxPending) {
            throw new DeviceFlowCapacityExceededException(self::CAPACITY_RETRY_AFTER);
        }
    }

    private function newUserCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::USER_CODE_LENGTH; ++$i) {
            $code .= self::USER_CODE_ALPHABET[random_int(0, strlen(self::USER_CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    private function pending(DeviceAuthorization $authorization): DevicePollResult
    {
        $now        = $this->clock->now();
        $lastPolled = $authorization->getLastPolledAt();
        $tooFast    = $lastPolled instanceof DateTimeImmutable && ($now->getTimestamp() - $lastPolled->getTimestamp()) < max(1, $this->intervalSeconds);
        $authorization->polled($now);
        $this->repository->save($authorization);

        return DevicePollResult::error($tooFast ? DevicePollResult::SLOW_DOWN : DevicePollResult::PENDING);
    }

    private function consumeDenied(DeviceAuthorization $authorization): DevicePollResult
    {
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $this->repository->remove($authorization);

        return DevicePollResult::error(DevicePollResult::DENIED);
    }

    private function exchange(DeviceAuthorization $authorization, ?string $clientIp): DevicePollResult
    {
        $user = $authorization->getUser();
        // Since 1.29 (audit SO-L1): an account disabled, locked or expired after the approval gets no token.
        if ($user instanceof UserInterface && $this->accountChecker instanceof ClientAccountChecker && !$this->accountChecker->isUserActive($user)) {
            return $this->consumeDenied($authorization);
        }
        if (!$user instanceof UserInterface || !$this->repository->markConsumed($authorization)) {
            return DevicePollResult::error(DevicePollResult::EXPIRED);
        }

        $token = $this->authService->issueToken($user, $authorization->getClientType(), $authorization->getCredentialId(), $authorization->getScopes());
        // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
        $this->repository->remove($authorization);
        $this->auditor->record(ClientApiAuditAction::TokenIssued, $user, $authorization->getCredentialId(), $authorization->getClientType(), $clientIp);

        return DevicePollResult::issued($token);
    }
}
