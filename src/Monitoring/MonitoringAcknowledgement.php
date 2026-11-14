<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use InvalidArgumentException;

use function preg_match;

/**
 * Since 1.26.0 (contract §24.2): a worker's acknowledgement of one monitoring policy version — the version, when the
 * server recorded it, the client that showed the notice (`desktop`, `extension` or `web`) and the SHA-256 of the
 * notice text the worker saw. The VO of {@see MonitoringAcknowledgementStoreInterface}.
 */
final readonly class MonitoringAcknowledgement
{
    /**
     * @throws InvalidArgumentException when a value is outside the contract
     */
    public function __construct(
        public int $policyVersion,
        public DateTimeImmutable $acknowledgedAt,
        public string $clientType,
        public string $noticeSha256,
    ) {
        if ($policyVersion < 1) {
            throw new InvalidArgumentException('An acknowledged policy version is at least 1.');
        }
        if (preg_match('/^[a-z]{1,16}$/D', $clientType) !== 1) {
            throw new InvalidArgumentException('The client type is 1-16 lowercase letters (desktop, extension, web).');
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $noticeSha256) !== 1) {
            throw new InvalidArgumentException('noticeSha256 must be a lowercase hex SHA-256.');
        }
    }
}
