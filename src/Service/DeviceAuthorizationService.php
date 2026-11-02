<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateInterval;
use DateTimeImmutable;
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
use Nowo\TimeTrackBundle\Exception\DeviceCodeAttemptsExceededException;
use Nowo\TimeTrackBundle\Repository\DeviceAuthorizationRepositoryInterface;
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
    /** Base-20 alphabet without vowels (no words, no 0/O 1/I confusion). */
    public const USER_CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    public const USER_CODE_LENGTH = 8;

    public const CLIENT_NAME_MAX_LENGTH = 100;

    private ClockInterface $clock;

    /**
     * @param int $codeTtlSeconds Lifetime of a request (`expiresIn`)
     * @param int $intervalSeconds Minimum polling interval (`interval`; faster polls get `slow_down`)
     * @param int $lookupLimit User code lookups per user per window
     * @param int $lookupWindowSeconds Window of the lookup limit
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
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Creates a pending request.
     *
     * @param string $clientName Name the client sent (control characters removed, trimmed, capped at 100 characters)
     * @param list<ClientScope> $scopes Requested scopes (empty = every scope)
     */
    public function start(ClientType $clientType, string $clientName, array $scopes = []): DeviceCodeGrant
    {
        $deviceCode = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $userCode   = $this->newUserCode();
        $now        = $this->clock->now();
        $ttl        = max(60, $this->codeTtlSeconds);

        $this->repository->save(new DeviceAuthorization(
            self::hash($deviceCode),
            self::hash($userCode),
            $clientType,
            $this->cleanClientName($clientName, $clientType),
            ClientScope::normalize($scopes),
            // @igor-ignore - Request-scoped value or I/O delegate (response, clock value, repository call); no worker state.
            $now->add(new DateInterval('PT' . $ttl . 'S')),
            $now,
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
     * @return string The credential id the host issued
     */
    public function approve(DeviceAuthorization $authorization, UserInterface $user, ?string $clientIp = null): string
    {
        $credentialId = $this->credentialIssuer->issueForDevice($user, $authorization->getClientName(), $authorization->getScopes());
        $authorization->approve($user, $credentialId);
        $this->repository->save($authorization);
        $this->auditor->record(ClientApiAuditAction::DeviceApproved, $user, $credentialId, $authorization->getClientType(), $clientIp);

        return $credentialId;
    }

    /**
     * Denies a pending request.
     */
    public function deny(DeviceAuthorization $authorization, UserInterface $user, ?string $clientIp = null): void
    {
        $authorization->deny($user);
        $this->repository->save($authorization);
        $this->auditor->record(ClientApiAuditAction::DeviceDenied, $user, null, $authorization->getClientType(), $clientIp);
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
