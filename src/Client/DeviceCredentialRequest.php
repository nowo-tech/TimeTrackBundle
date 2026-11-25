<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 2.2.0 (Beacon Desktop BD-2): what a {@see ClientFamilyCredentialMinterInterface} gets when the user approves a
 * device request: who approved it, the device, the scope family and its scopes, and the device key that groups the
 * device's credentials across families (one "connected app" row with one badge per family, Q-BD-19). Value object.
 */
final readonly class DeviceCredentialRequest
{
    /**
     * @param TimeTrackUserRef $user User who approved the device
     * @param ClientType $clientType Client type the device declared
     * @param string $clientName Name the device sent (trimmed and length-capped by the bundle)
     * @param string $scopeFamily Scope family of the request ({@see DeviceScopeFamily::TIME} or a host family)
     * @param list<string> $scopes Approved scope values of that family
     * @param string $deviceKey Device key (UUID) shared by every credential of the same device
     */
    public function __construct(
        public TimeTrackUserRef $user,
        public ClientType $clientType,
        public string $clientName,
        public string $scopeFamily,
        public array $scopes,
        public string $deviceKey,
    ) {
    }
}
