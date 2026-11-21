<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;

/**
 * Since 1.35.0 (DDD-host checklist B7): one client session (a desktop app's or browser extension's sign-in) of a user,
 * without its token hash. Its id is what `ClientTokenRevoker::revokeById()` takes. Built per call and never kept.
 */
final readonly class ClientSessionView
{
    /**
     * @param string $id Session id
     * @param string $ownerId The owner's user id
     * @param ClientType $clientType The client that holds the session
     * @param string|null $credentialId The host credential it was issued from (an app password, a device approval)
     * @param list<string> $scopes Granted scope values
     * @param DateTimeImmutable $createdAt Sign-in time
     * @param DateTimeImmutable|null $lastUsedAt Last authenticated call
     * @param DateTimeImmutable $expiresAt Expiry
     */
    public function __construct(
        public string $id,
        public string $ownerId,
        public ClientType $clientType,
        public ?string $credentialId,
        public array $scopes,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
