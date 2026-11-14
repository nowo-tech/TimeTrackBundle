<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Monitoring\HmacMonitoringUploadSigner;
use Nowo\TimeTrackBundle\Monitoring\MonitoringUploadClaim;
use PHPUnit\Framework\TestCase;

use function hash_hmac;
use function str_repeat;
use function strtoupper;

/**
 * Since 1.26.0: the default upload signer — HMAC-SHA256 over every claim field with a key derived from the configured
 * secret (never the raw secret), constant-time verification, no state.
 */
final class HmacMonitoringUploadSignerTest extends TestCase
{
    public function testItSignsEveryFieldWithADerivedKey(): void
    {
        $secret = 'REDACTED-TEST-secret';
        $signer = new HmacMonitoringUploadSigner($secret);
        $claim  = new MonitoringUploadClaim('upload', str_repeat('t', 64), str_repeat('s', 64), 100, 'image/webp', 1_800_000_000);
        $sig    = $signer->sign($claim);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sig);
        self::assertSame(hash_hmac('sha256', $claim->canonical(), hash_hmac('sha256', HmacMonitoringUploadSigner::PURPOSE, $secret, true)), $sig);
        self::assertNotSame(hash_hmac('sha256', $claim->canonical(), $secret), $sig, 'the raw secret is never the key');
        self::assertTrue($signer->verify($claim, $sig));
        self::assertFalse($signer->verify($claim, strtoupper($sig)));
        self::assertFalse($signer->verify($claim, 'short'));
        self::assertFalse((new HmacMonitoringUploadSigner('REDACTED-TEST-other'))->verify($claim, $sig));
        foreach ([
            new MonitoringUploadClaim('other', str_repeat('t', 64), str_repeat('s', 64), 100, 'image/webp', 1_800_000_000),
            new MonitoringUploadClaim('upload', str_repeat('x', 64), str_repeat('s', 64), 100, 'image/webp', 1_800_000_000),
            new MonitoringUploadClaim('upload', str_repeat('t', 64), str_repeat('x', 64), 100, 'image/webp', 1_800_000_000),
            new MonitoringUploadClaim('upload', str_repeat('t', 64), str_repeat('s', 64), 101, 'image/webp', 1_800_000_000),
            new MonitoringUploadClaim('upload', str_repeat('t', 64), str_repeat('s', 64), 100, 'image/jpeg', 1_800_000_000),
            new MonitoringUploadClaim('upload', str_repeat('t', 64), str_repeat('s', 64), 100, 'image/webp', 1_800_000_001),
        ] as $tampered) {
            self::assertFalse($signer->verify($tampered, $sig));
        }
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HmacMonitoringUploadSigner('');
    }
}
