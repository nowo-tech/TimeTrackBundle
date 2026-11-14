<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Monitoring;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

use function get_resource_type;
use function in_array;
use function is_resource;
use function stream_get_meta_data;

/**
 * Since 1.26.0 (contract §24.3 / §24.6): one checked screenshot the bundle hands to
 * {@see MonitoringEvidenceStoreInterface::storeScreenshot()} — whose it is, the entry and slot it belongs to, when it was
 * taken, under which policy version, whether the client blurred it, and the bytes as a **read-only stream** (MIME
 * sniffed from the magic bytes, dimensions read from the image header, SHA-256 and size checked against the intent).
 *
 * The stream is open for the duration of the call only: read it (or copy it) inside `storeScreenshot()`; the bundle
 * closes it and deletes its temporary file right after. Never keep the VO.
 */
final readonly class ScreenshotUpload
{
    /** @var resource */
    private mixed $stream;

    /**
     * @param resource $stream Read-only stream positioned at the first byte
     *
     * @throws InvalidArgumentException when the stream is not a readable, read-only stream
     */
    public function __construct(
        public TimeTrackUserRef $user,
        public string $entryId,
        public DateTimeImmutable $slot,
        public DateTimeImmutable $takenAt,
        public int $policyVersion,
        public bool $blurred,
        public string $mime,
        public int $bytes,
        public int $width,
        public int $height,
        public string $sha256,
        mixed $stream,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' || !in_array(stream_get_meta_data($stream)['mode'], ['r', 'rb'], true)) {
            throw new InvalidArgumentException('A screenshot upload needs an open, read-only stream.');
        }
        $this->stream = $stream;
    }

    /**
     * The read-only stream of the image bytes.
     *
     * @return resource
     */
    public function stream(): mixed
    {
        return $this->stream;
    }
}
