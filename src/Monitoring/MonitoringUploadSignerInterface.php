<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

/**
 * Since 1.26.0 (contract §24.3 / §24.6): signs and verifies the short-lived upload URL of a screenshot intent.
 * Standard default: {@see HmacMonitoringUploadSigner} (HMAC-SHA256, key derived per call from `kernel.secret` or
 * `client_api.monitoring.signing_key`). A host may alias this interface to its own signer (e.g. a key held in its
 * settings store, verified with the current and the previous key during a rotation). Framework-free.
 */
interface MonitoringUploadSignerInterface
{
    /**
     * The signature of the claim (URL-safe characters only).
     */
    public function sign(MonitoringUploadClaim $claim): string;

    /**
     * Whether the signature is valid for the claim. Compare in constant time.
     */
    public function verify(MonitoringUploadClaim $claim, string $signature): bool;
}
