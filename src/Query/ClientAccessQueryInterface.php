<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

/**
 * Since 1.35.0 (DDD-host checklist B7): the read port of a user's client sessions and device sign-ins, for the host's
 * account pages. Views only, never a token hash or a device code. No access rule (the host decides). Public service.
 * Stateless (FrankenPHP worker mode, kernel reset false).
 */
interface ClientAccessQueryInterface
{
    /**
     * The user's client sessions, newest first.
     *
     * @param string $ownerId The user id, compared byte for byte
     * @param bool $includeExpired Also the expired sessions the purge has not removed yet
     *
     * @return list<ClientSessionView>
     */
    public function sessionsOf(string $ownerId, bool $includeExpired = false): array;

    /**
     * The device sign-in requests the user approved or denied, newest first.
     *
     * @param string $ownerId The user id, compared byte for byte
     *
     * @return list<DeviceSignInView>
     */
    public function deviceSignInsOf(string $ownerId): array;
}
