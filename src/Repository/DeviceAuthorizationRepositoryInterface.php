<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Repository;

use Nowo\TimeTrackBundle\Entity\DeviceAuthorization;

/**
 * Store of the device authorization requests (hashed codes).
 */
interface DeviceAuthorizationRepositoryInterface
{
    /**
     * Persists a request.
     */
    public function save(DeviceAuthorization $authorization): void;

    /**
     * Deletes a request.
     */
    public function remove(DeviceAuthorization $authorization): void;

    /**
     * Request with this device code hash (any state, expired included).
     */
    public function findByDeviceCodeHash(string $deviceCodeHash): ?DeviceAuthorization;

    /**
     * Pending, not expired request with this user code hash.
     */
    public function findPendingByUserCodeHash(string $userCodeHash): ?DeviceAuthorization;

    /**
     * Pending, not expired request by id.
     */
    public function findPendingById(string $id): ?DeviceAuthorization;

    /**
     * Atomically moves an approved request to consumed; false when another exchange won (single use).
     */
    public function markConsumed(DeviceAuthorization $authorization): bool;

    /**
     * Deletes the expired requests and returns how many were deleted.
     */
    public function purgeExpired(): int;

    /**
     * Number of requests that have not expired yet (any state): the global cap of `device/code`.
     */
    public function countUnexpired(): int;
}
