<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;

/**
 * Since 2.2.0 (Beacon Desktop, Q-BD-19): one connected device of a user — its client sessions of every scope family,
 * grouped by device key — for a "connected apps" page with one row per device and one badge per family, each revocable
 * on its own (`ClientTokenRevoker::revokeById()` with a session id, or `revokeByCredential()`). Built per call and never
 * kept.
 */
final readonly class ConnectedDeviceView
{
    /**
     * @param string $deviceKey Device key (the session id of a session issued before 2.2 or by a password login)
     * @param ClientType $clientType Client of the newest session
     * @param string|null $clientName Name the device gave (newest known)
     * @param list<string> $scopeFamilies The device's families (badges), `time` first, then by name
     * @param list<ClientSessionView> $sessions The device's sessions, newest first
     * @param DateTimeImmutable $connectedAt Oldest sign-in of the device's sessions
     * @param DateTimeImmutable|null $lastUsedAt Most recent authenticated call of any of its sessions
     */
    public function __construct(
        public string $deviceKey,
        public ClientType $clientType,
        public ?string $clientName,
        public array $scopeFamilies,
        public array $sessions,
        public DateTimeImmutable $connectedAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {
    }

    /**
     * The device's sessions of one family, newest first.
     *
     * @return list<ClientSessionView>
     */
    public function sessionsOf(string $scopeFamily): array
    {
        $sessions = [];
        foreach ($this->sessions as $session) {
            if ($session->scopeFamily === $scopeFamily) {
                $sessions[] = $session;
            }
        }

        return $sessions;
    }
}
