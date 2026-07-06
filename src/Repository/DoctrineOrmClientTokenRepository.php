<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TimeTrackBundle\Entity\ClientToken;

final readonly class DoctrineOrmClientTokenRepository implements ClientTokenRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ClientToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function remove(ClientToken $token): void
    {
        $this->entityManager->remove($token);
        $this->entityManager->flush();
    }

    public function findValidByTokenHash(string $tokenHash): ?ClientToken
    {
        /** @var ClientToken|null $token */
        $token = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(ClientToken::class, 't')
            ->where('t.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$token instanceof ClientToken || $token->isExpired()) {
            return null;
        }

        return $token;
    }

    public function purgeExpired(): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(ClientToken::class, 't')
            ->where('t.expiresAt <= :now')
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
