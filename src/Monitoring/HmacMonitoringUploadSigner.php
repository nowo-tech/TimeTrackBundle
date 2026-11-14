<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use InvalidArgumentException;
use SensitiveParameter;

use function hash_equals;
use function hash_hmac;
use function preg_match;

/**
 * Since 1.26.0: the default {@see MonitoringUploadSignerInterface} — lowercase hex HMAC-SHA256 of
 * {@see MonitoringUploadClaim::canonical()}. The key is derived on every call (HMAC of a fixed purpose string with the
 * configured secret: `client_api.monitoring.signing_key`, or `kernel.secret`), so the signing key is never the raw
 * application secret and nothing is cached in the service or a static (FrankenPHP worker mode, kernel reset false).
 */
final readonly class HmacMonitoringUploadSigner implements MonitoringUploadSignerInterface
{
    /** Domain separation of the derived key. */
    public const PURPOSE = 'nowo_time_track.monitoring_upload_url.v1';

    /**
     * @param string $secret `client_api.monitoring.signing_key`, or `kernel.secret`
     *
     * @throws InvalidArgumentException when the secret is empty
     */
    public function __construct(#[SensitiveParameter] private string $secret)
    {
        if ($secret === '') {
            throw new InvalidArgumentException('The monitoring upload signing secret is empty (client_api.monitoring.signing_key or kernel.secret).');
        }
    }

    /**
     * The hex HMAC-SHA256 of the claim.
     */
    public function sign(MonitoringUploadClaim $claim): string
    {
        return hash_hmac('sha256', $claim->canonical(), hash_hmac('sha256', self::PURPOSE, $this->secret, true));
    }

    /**
     * Constant-time comparison with the expected signature.
     */
    public function verify(MonitoringUploadClaim $claim, string $signature): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $signature) === 1 && hash_equals($this->sign($claim), $signature);
    }
}
