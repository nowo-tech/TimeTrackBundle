<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Nowo\TimeTrackBundle\Monitoring\MonitoringPolicy;

use function chr;
use function hash;
use function pack;
use function str_repeat;
use function strlen;

/**
 * Monitoring test data: a policy, and minimal JPEG / WebP files whose header carries the given dimensions (the
 * bundle reads the header only).
 */
final class MonitoringFixtures
{
    /** Text of the notice of every fixture policy. */
    public const NOTICE = 'What is collected, when, who sees it, how long, your rights, how to discard.';

    /**
     * @param list<string> $kinds
     * @param array<string, mixed> $overrides named arguments of MonitoringPolicy
     */
    public static function policy(int $version = 7, array $kinds = ['screenshots', 'apps', 'activity'], array $overrides = []): MonitoringPolicy
    {
        return new MonitoringPolicy(...$overrides + [
            'enabled'        => true,
            'policyVersion'  => $version,
            'noticeUrl'      => 'https://beacon.example/monitoring-notice/' . $version,
            'noticeSha256'   => self::noticeSha256(),
            'kinds'          => $kinds,
            'dpiaReference'  => 'DPIA-2026-003',
            'dpiaRecordedAt' => '2026-11-10',
            'excludedApps'   => ['1password', 'keepassxc'],
        ]);
    }

    public static function noticeSha256(): string
    {
        return hash('sha256', self::NOTICE);
    }

    /**
     * A baseline JPEG (SOI, JFIF, SOF0, EOI) of the given size, padded with a comment to `$pad` extra bytes.
     */
    public static function jpeg(int $width, int $height, int $pad = 0): string
    {
        $comment = $pad > 0 ? "\xFF\xFE" . pack('n', $pad + 2) . str_repeat('x', $pad) : '';

        return "\xFF\xD8\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            . $comment
            . "\xFF\xC0" . pack('n', 17) . chr(8) . pack('n', $height) . pack('n', $width) . "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01"
            . "\xFF\xD9";
    }

    /**
     * A lossless WebP (VP8L) header of the given size.
     */
    public static function webp(int $width, int $height): string
    {
        $payload = "\x2f" . pack('V', ($width - 1) | (($height - 1) << 14)) . "\x00\x00\x00\x00\x00";
        $chunk   = 'VP8L' . pack('V', strlen($payload)) . $payload;

        return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
    }
}
