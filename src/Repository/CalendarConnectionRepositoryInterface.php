<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Entity\CalendarConnection;
use Nowo\TimeTrackBundle\Enum\CalendarProvider;

/**
 * Since 1.14: store of the calendar connections (contract §3.5).
 */
interface CalendarConnectionRepositoryInterface
{
    /**
     * Persists and flushes a connection.
     */
    public function saveConnection(CalendarConnection $connection): void;

    /**
     * The connection with this id.
     */
    public function findConnection(string $id): ?CalendarConnection;

    /**
     * The connection of a user, scope and provider.
     */
    public function findConnectionFor(string $userId, string $scopeKey, CalendarProvider $provider): ?CalendarConnection;

    /**
     * Connections of a user (one scope or all), oldest first.
     *
     * @return list<CalendarConnection>
     */
    public function findConnectionsByUser(string $userId, ?string $scopeKey = null): array;

    /**
     * Server-mode connections (active or in error) never synced or last synced before `$syncedBefore`, least recently
     * synced first.
     *
     * @return list<CalendarConnection>
     */
    public function findConnectionsDue(DateTimeImmutable $syncedBefore, ?string $userId, int $limit): array;

    /**
     * Deletes the connection, its suggestions and the owner's rules in its scope, in one transaction.
     */
    public function deleteConnection(CalendarConnection $connection): void;
}
