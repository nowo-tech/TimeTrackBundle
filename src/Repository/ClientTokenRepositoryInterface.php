<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\ClientToken;

interface ClientTokenRepositoryInterface
{
    public function save(ClientToken $token): void;

    public function remove(ClientToken $token): void;

    public function findValidByTokenHash(string $tokenHash): ?ClientToken;

    public function purgeExpired(): int;
}
