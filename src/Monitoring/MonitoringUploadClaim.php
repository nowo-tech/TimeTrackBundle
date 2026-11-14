<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use function implode;

/**
 * Since 1.26.0 (contract §24.3): what a signed upload URL is bound to — the upload id, the SHA-256 of the Bearer token
 * that asked for it, the declared SHA-256, size and MIME type of the image and the expiry (Unix seconds). The VO of
 * {@see MonitoringUploadSignerInterface}.
 */
final readonly class MonitoringUploadClaim
{
    /**
     * Receives the signed fields.
     */
    public function __construct(
        public string $uploadId,
        public string $tokenHash,
        public string $sha256,
        public int $bytes,
        public string $mime,
        public int $expiresAt,
    ) {
    }

    /**
     * The canonical string a signer authenticates: the fields in contract order, separated by line feeds (no field can
     * hold one).
     */
    public function canonical(): string
    {
        return implode("\n", ['nowo-time-track-monitoring-upload-v1', $this->uploadId, $this->tokenHash, $this->sha256, (string) $this->bytes, $this->mime, (string) $this->expiresAt]);
    }
}
