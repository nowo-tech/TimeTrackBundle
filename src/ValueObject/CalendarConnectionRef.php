<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\ValueObject;

use Nowo\TimeTrackBundle\Calendar\CalendarCredentialSourceInterface;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Enum\CalendarConnectionMode;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;
use Nowo\TimeTrackBundle\Support\UserIdResolver;

/**
 * Since 1.23.0 (REQ-ARCH-001 rules 1 and 3): read-only snapshot of a calendar connection, what
 * {@see CalendarCredentialSourceInterface} receives instead of the Doctrine entity.
 */
final readonly class CalendarConnectionRef
{
    /**
     * @param string $id connection id (UUID)
     * @param string|null $userId id of the connection owner as a string (null when the owner has no usable id)
     * @param string $scopeKey tenant scope of the connection
     * @param CalendarProvider $provider calendar provider
     * @param CalendarConnectionMode $mode server or client mode
     * @param string|null $credentialRef the host's credential reference (null when none)
     */
    public function __construct(
        public string $id,
        public ?string $userId,
        public string $scopeKey,
        public CalendarProvider $provider,
        public CalendarConnectionMode $mode,
        public ?string $credentialRef,
    ) {
    }

    /**
     * Snapshot of the connection's current values.
     */
    public static function fromConnection(CalendarConnection $connection): self
    {
        return new self($connection->getId(), UserIdResolver::idOf($connection->getUser()), $connection->getScopeKey(), $connection->getProvider(), $connection->getMode(), $connection->getCredentialRef());
    }
}
