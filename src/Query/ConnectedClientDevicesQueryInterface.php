<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

/**
 * Since 2.2.0 (Beacon Desktop, Q-BD-19): read port of a user's connected devices — the client sessions of
 * {@see ClientAccessQueryInterface::sessionsOf()} grouped by device key, with one badge per scope family. Views only,
 * never a token hash. No access rule (the host decides). Public service. Stateless (FrankenPHP worker mode, kernel reset
 * false).
 */
interface ConnectedClientDevicesQueryInterface
{
    /**
     * The user's devices with at least one unexpired session, most recently used first.
     *
     * @param string $ownerId The user id, compared byte for byte
     *
     * @return list<ConnectedDeviceView>
     */
    public function devicesOf(string $ownerId): array;
}
