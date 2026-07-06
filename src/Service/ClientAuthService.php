<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Service;

use DateInterval;
use DateTimeImmutable;
use Nowo\TimeTrackBundle\Client\ClientAuthenticatorInterface;
use Nowo\TimeTrackBundle\Client\ClientAuthResult;
use Nowo\TimeTrackBundle\Entity\ClientToken;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Repository\ClientTokenRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function base64_encode;
use function hash;
use function random_bytes;
use function rtrim;
use function strtr;

use const DATE_ATOM;

final readonly class ClientAuthService
{
    private const LAST_USED_TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(
        private ClientAuthenticatorInterface $authenticator,
        private ClientTokenRepositoryInterface $tokenRepository,
        private int $tokenTtlSeconds,
    ) {
    }

    /**
     * @return array{token: string, expiresAt: string}|null
     */
    public function login(string $username, string $password, ClientType $clientType): ?array
    {
        $result = $this->authenticator->authenticate($username, $password);
        if (!$result instanceof ClientAuthResult || !$result->isSuccess()) {
            return null;
        }

        return $this->issueToken($result->getUser(), $clientType);
    }

    public function resolveUser(string $plainToken): ?UserInterface
    {
        $entity = $this->tokenRepository->findValidByTokenHash(self::hashToken($plainToken));
        if (!$entity instanceof ClientToken) {
            return null;
        }

        $this->touchIfStale($entity);

        $user = $entity->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    public function logout(string $plainToken): void
    {
        $entity = $this->tokenRepository->findValidByTokenHash(self::hashToken($plainToken));
        if ($entity instanceof ClientToken) {
            $this->tokenRepository->remove($entity);
        }
    }

    /**
     * @return array{token: string, expiresAt: string}
     */
    private function issueToken(UserInterface $user, ClientType $clientType): array
    {
        $plainToken = $this->generatePlainToken();
        $expiresAt  = (new DateTimeImmutable())->add(new DateInterval('PT' . max(1, $this->tokenTtlSeconds) . 'S'));

        $this->tokenRepository->save(new ClientToken(
            self::hashToken($plainToken),
            $expiresAt,
            $user,
            $clientType,
        ));

        return [
            'token'     => $plainToken,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
        ];
    }

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
        $now      = new DateTimeImmutable();

        if ($lastUsed instanceof DateTimeImmutable && ($now->getTimestamp() - $lastUsed->getTimestamp()) < self::LAST_USED_TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $entity->touch();
        $this->tokenRepository->save($entity);
    }
}
