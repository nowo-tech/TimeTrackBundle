<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateInterval;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Doctrine\ManagedEntityRefresher;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientScope;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Integration\PassThroughUserReferenceResolver;
use Nowo\TimeTrackBundle\Integration\UserReferenceResolverInterface;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\User\UserInterface;

use function base64_encode;
use function hash;
use function random_bytes;
use function rtrim;
use function strtr;

use const DATE_ATOM;

/**
 * Bearer tokens of the browser extension and the desktop app: login (issues a token, stored hashed), token
 * resolution and logout.
 *
 * Logging (REQ-OBS-001): login success / failure and logout with the client type only — never the username, the
 * password, the token (plain or hashed) or the client IP.
 */
final readonly class ClientAuthService
{
    private UserReferenceResolverInterface $userReferences;

    private const LAST_USED_TOUCH_INTERVAL_SECONDS = 300;

    private ClockInterface $clock;

    private LoggerInterface $logger;

    /**
     * @param ClientAuthenticatorInterface $authenticator Checks the client credentials
     * @param ClientTokenRepositoryInterface $tokenRepository Stores the hashed tokens
     * @param int $tokenTtlSeconds Lifetime of an issued token
     * @param ManagerRegistry|null $managerRegistry Refreshes the token user in worker mode
     * @param ClockInterface|null $clock Time source (defaults to the system clock)
     * @param LoggerInterface|null $logger Operational log (defaults to no logging)
     */
    public function __construct(
        private ClientAuthenticatorInterface $authenticator,
        private ClientTokenRepositoryInterface $tokenRepository,
        private int $tokenTtlSeconds,
        private ?ManagerRegistry $managerRegistry = null,
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?UserReferenceResolverInterface $userReferences = null,
    ) {
        $this->clock          = $clock ?? new NativeClock();
        $this->logger         = $logger ?? new NullLogger();
        $this->userReferences = $userReferences ?? new PassThroughUserReferenceResolver();
    }

    /**
     * Checks the credentials and issues a Bearer token.
     *
     * @param string $username Login name (never logged)
     * @param string $password Plain password (never logged)
     * @param ClientType $clientType Client asking for the token
     *
     * @return array{token: string, expiresAt: string, scopes: list<string>}|null The plain token, its expiry and scopes, or null on bad credentials
     */
    public function login(string $username, string $password, ClientType $clientType): ?array
    {
        $result = $this->authenticate($username, $password, $clientType);

        return $result instanceof ClientAuthResult
            ? $this->issueToken($result->requireUser(), $clientType, $result->getCredentialId(), $result->getScopes())
            : null;
    }

    /**
     * Checks the credentials through the host authenticator (logs success / failure without personal data).
     *
     * @return ClientAuthResult|null The successful result, or null on bad credentials
     */
    public function authenticate(string $username, string $password, ClientType $clientType): ?ClientAuthResult
    {
        $result = $this->authenticator->authenticate($username, $password);
        if (!$result instanceof ClientAuthResult || !$result->isSuccess()) {
            $this->logger->notice('TimeTrack client login failed.', $this->context('client_login', $clientType));

            return null;
        }

        $this->logger->info('TimeTrack client login succeeded.', $this->context('client_login', $clientType));

        return $result;
    }

    /**
     * Resolves the user of a valid (not expired) Bearer token and refreshes its last-used time every 5 minutes.
     *
     * @param string $plainToken Token sent by the client
     *
     * @return UserInterface|null The token user, or null for an unknown or expired token
     */
    public function resolveUser(string $plainToken): ?UserInterface
    {
        $user = $this->resolveToken($plainToken)?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * Resolves a valid (not expired) Bearer token whose owner is a security user (refreshed in worker mode) and
     * refreshes its last-used time every 5 minutes.
     *
     * @param string $plainToken Token sent by the client
     *
     * @return ClientToken|null The token, or null for an unknown or expired token
     */
    public function resolveToken(string $plainToken): ?ClientToken
    {
        $entity = $this->tokenRepository->findValidByTokenHash(self::hashToken($plainToken));
        if (!$entity instanceof ClientToken) {
            return null;
        }

        $user = $entity->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }

        $this->touchIfStale($entity);
        ManagedEntityRefresher::refresh($this->managerRegistry, $user);

        return $entity;
    }

    /**
     * Revokes a Bearer token (no-op for an unknown or expired token).
     *
     * @param string $plainToken Token sent by the client
     *
     * @return ClientToken|null The revoked token (detached), or null when nothing was revoked
     */
    public function logout(string $plainToken): ?ClientToken
    {
        $entity = $this->tokenRepository->findValidByTokenHash(self::hashToken($plainToken));
        if (!$entity instanceof ClientToken) {
            return null;
        }

        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->tokenRepository->remove($entity);
        $this->logger->info('TimeTrack client token revoked.', $this->context('client_logout', $entity->getClientType()));

        return $entity;
    }

    /**
     * Issues a session token linked to a host credential (login and device flow).
     *
     * @param UserInterface $user Token owner
     * @param ClientType $clientType Client holding the token
     * @param string|null $credentialId Host credential the token comes from
     * @param list<ClientScope>|null $scopes Granted scopes (null = the default scopes; an empty list grants none)
     *
     * @return array{token: string, expiresAt: string, scopes: list<string>}
     */
    public function issueToken(UserInterface $user, ClientType $clientType, ?string $credentialId = null, ?array $scopes = null): array
    {
        $scopes     = ClientScope::normalize($scopes);
        $plainToken = $this->generatePlainToken();
        $now        = $this->clock->now();
        // @igor-ignore - DateTimeImmutable::add() returns a new instance; the clock value is not mutated.
        $expiresAt = $now->add(new DateInterval('PT' . max(1, $this->tokenTtlSeconds) . 'S'));

        $this->tokenRepository->save(new ClientToken(
            self::hashToken($plainToken),
            $expiresAt,
            $this->userReferences->reference($user),
            $clientType,
            $now,
            $credentialId,
            ClientScope::values($scopes),
        ));

        return [
            'token'     => $plainToken,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
            'scopes'    => ClientScope::values($scopes),
        ];
    }

    /**
     * SHA-256 of a plain token: the only form stored in the database.
     *
     * @param string $plainToken Token sent by the client
     *
     * @return string Hex digest
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function generatePlainToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function touchIfStale(ClientToken $entity): void
    {
        $lastUsed = $entity->getLastUsedAt();
        $now      = $this->clock->now();

        if ($lastUsed instanceof DateTimeImmutable && ($now->getTimestamp() - $lastUsed->getTimestamp()) < self::LAST_USED_TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $entity->touch($now);
        $this->tokenRepository->save($entity);
    }

    /**
     * Log context without personal data (REQ-OBS-001).
     *
     * @return array{bundle: string, action: string, client: string}
     */
    private function context(string $action, ClientType $clientType): array
    {
        return ['bundle' => 'time_track', 'action' => $action, 'client' => $clientType->value];
    }
}
