<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Query;

use DateTimeImmutable;
use Nowo\TimeTrackBundle\Enum\ClientType;
use Nowo\TimeTrackBundle\Enum\DeviceAuthorizationStatus;

/**
 * Since 1.35.0 (DDD-host checklist B7): one device sign-in request a user approved or denied, without its codes. Built
 * per call and never kept.
 */
final readonly class DeviceSignInView
{
    /**
     * @param string $id Request id
     * @param string $ownerId The user who approved or denied it
     * @param ClientType $clientType The requesting client
     * @param string $clientName The name the device gave
     * @param DeviceAuthorizationStatus $status Approved, denied, consumed…
     * @param list<string> $scopes Requested scope values
     * @param string|null $ipPrefix IPv4 /24 or IPv6 /64 of the device
     * @param DateTimeImmutable $createdAt Request time
     * @param DateTimeImmutable $expiresAt Expiry of the request
     * @param DateTimeImmutable|null $lastPolledAt Last poll of the device
     * @param string $scopeFamily Since 2.2.0: the requested scope family
     */
    public function __construct(
        public string $id,
        public string $ownerId,
        public ClientType $clientType,
        public string $clientName,
        public DeviceAuthorizationStatus $status,
        public array $scopes,
        public ?string $ipPrefix,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $lastPolledAt,
        public string $scopeFamily = 'time',
    ) {
    }
}
