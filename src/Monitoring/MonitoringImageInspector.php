<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use function getimagesize;
use function in_array;
use function is_array;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Since 1.26.0 (contract §24.3): what an uploaded screenshot really is — the MIME type from its magic bytes (WebP or
 * JPEG only; the declared type and the `Content-Type` header are never trusted) and its dimensions from the image
 * header (`getimagesize()`, which reads the header only and never decodes pixels, so a decompression bomb costs
 * nothing here; the host re-encodes with its own pixel limit). Stateless.
 */
final class MonitoringImageInspector
{
    public const MIME_WEBP = 'image/webp';

    public const MIME_JPEG = 'image/jpeg';

    /** Accepted types. */
    public const MIMES = [self::MIME_WEBP, self::MIME_JPEG];

    /** Most pixels of an accepted screenshot (3840 × 3840). */
    public const MAX_PIXELS = MonitoringPolicy::MAX_LONG_SIDE_PX * MonitoringPolicy::MAX_LONG_SIDE_PX;

    /**
     * The MIME type of the first bytes of a file (at least 12), or null when it is neither WebP nor JPEG.
     */
    public static function sniff(string $head): ?string
    {
        if (strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return self::MIME_WEBP;
        }
        if (strlen($head) >= 3 && str_starts_with($head, "\xFF\xD8\xFF")) {
            return self::MIME_JPEG;
        }

        return null;
    }

    /**
     * `[width, height, mime]` from the image header of a file, or null when the header cannot be read or is not WebP or
     * JPEG. The caller compares them with the declared ones (at most 3840 px a side, so at most {@see self::MAX_PIXELS}
     * pixels).
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    public static function dimensions(string $path): ?array
    {
        $info = @getimagesize($path);
        if (!is_array($info)) {
            return null;
        }
        [$width, $height, $mime] = [$info[0], $info[1], $info['mime']];
        if (!in_array($mime, self::MIMES, true) || $width < 1 || $height < 1) {
            return null;
        }

        return [$width, $height, $mime];
    }
}
