<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

use Nowo\TimeTrackBundle\Enum\ClientType;

/**
 * Since 1.34.0: the fields of one stored client session the revocation by id needs (read as scalars: no entity is
 * loaded into any entity manager). Never carries the token hash.
 */
final readonly class ClientSessionRecord
{
    /**
     * @param string $id Session id (UUID, as stored)
     * @param string $ownerId Id of the session owner (the `user_id` column)
     * @param string|null $credentialId Host credential the session was issued from
     * @param ClientType $clientType Client that holds the session
     * @param string $scopeFamily Since 2.2.0: scope family of the session (`time` for every older row)
     */
    public function __construct(
        public string $id,
        public string $ownerId,
        public ?string $credentialId,
        public ClientType $clientType,
        public string $scopeFamily = 'time',
    ) {
    }
}
