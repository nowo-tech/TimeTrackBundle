<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Monitoring;

use Nowo\TimeTrackBundle\Monitoring\MonitoringImageInspector;
use Nowo\TimeTrackBundle\Tests\Support\MonitoringFixtures;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Since 1.26.0: MIME from the magic bytes only, dimensions from the image header only (never a pixel decode).
 */
final class MonitoringImageInspectorTest extends TestCase
{
    public function testSniff(): void
    {
        self::assertSame('image/webp', MonitoringImageInspector::sniff(MonitoringFixtures::webp(10, 10)));
        self::assertSame('image/jpeg', MonitoringImageInspector::sniff(MonitoringFixtures::jpeg(10, 10)));
        self::assertNull(MonitoringImageInspector::sniff('RIFF'));
        self::assertNull(MonitoringImageInspector::sniff("\xFF\xD8"));
        self::assertNull(MonitoringImageInspector::sniff("\x89PNG\r\n\x1A\n0000"));
        self::assertNull(MonitoringImageInspector::sniff('RIFF0000WAVEfmt '));
    }

    public function testDimensions(): void
    {
        self::assertSame([1280, 720, 'image/webp'], $this->dimensions(MonitoringFixtures::webp(1280, 720)));
        self::assertSame([3841, 100, 'image/webp'], $this->dimensions(MonitoringFixtures::webp(3841, 100)), 'the caller compares with the declared (capped) size');
        self::assertSame([800, 600, 'image/jpeg'], $this->dimensions(MonitoringFixtures::jpeg(800, 600, 32)));
        self::assertNull($this->dimensions('not an image at all'));
        self::assertNull($this->dimensions("GIF89a\x01\x00\x01\x00\x00\x00\x00;"), 'a GIF is not accepted');
        self::assertNull($this->dimensions(MonitoringFixtures::jpeg(0, 10)));
    }

    /**
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function dimensions(string $bytes): ?array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tt_inspector_');
        file_put_contents($path, $bytes);
        try {
            return MonitoringImageInspector::dimensions($path);
        } finally {
            unlink($path);
        }
    }
}
