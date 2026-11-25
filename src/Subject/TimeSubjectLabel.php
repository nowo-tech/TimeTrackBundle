<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Support\NoteSanitizer;

use function is_array;
use function mb_substr;
use function parse_url;
use function preg_match;
use function strlen;
use function strtolower;

/**
 * Since 2.1.0 (Beacon spec 256): how a subject the actor may read is shown: a plain-text label, an optional link and an
 * optional secondary line for pickers (e.g. a tax id or a city).
 *
 * The constructor cleans the texts with the note rules ({@see NoteSanitizer}: control, bidi-override and invisible
 * characters removed) and cuts them to {@see self::MAX_LENGTH} characters; a label empty after cleaning is refused. A URL
 * that is not an absolute `https` URL without credentials, or longer than {@see self::URL_MAX_LENGTH} bytes, is dropped
 * (the client API also requires `client_api.task_url.origin`). Labels are plain text: every surface escapes them.
 * Framework-free, immutable, built per call.
 */
final readonly class TimeSubjectLabel
{
    /** Longest label and hint, in characters. */
    public const MAX_LENGTH = 255;

    /** Longest URL kept, in bytes. */
    public const URL_MAX_LENGTH = 2048;

    /** The cleaned label (1–255 characters). */
    public string $label;

    /** The link, or null. */
    public ?string $url;

    /** The cleaned secondary line, or null. */
    public ?string $hint;

    /**
     * @param TimeSubjectRef $subject The subject described
     * @param string $label The name to show
     * @param string|null $url Link to the subject in the host (absolute https)
     * @param string|null $hint Secondary line in pickers
     *
     * @throws InvalidArgumentException When nothing is left of the label after cleaning
     */
    public function __construct(
        public TimeSubjectRef $subject,
        string $label,
        ?string $url = null,
        ?string $hint = null,
    ) {
        $clean = self::clean($label);
        if ($clean === null) {
            throw new InvalidArgumentException('A TimeTrack subject label cannot be empty.');
        }
        $this->label = $clean;
        $this->url   = self::safeUrl($url);
        $this->hint  = $hint === null ? null : self::clean($hint);
    }

    /**
     * A text cleaned with the note rules and cut to {@see self::MAX_LENGTH} characters; null when nothing is left.
     */
    public static function clean(string $text): ?string
    {
        $clean = NoteSanitizer::clean($text);

        return $clean === '' ? null : mb_substr($clean, 0, self::MAX_LENGTH);
    }

    /**
     * The URL when it is an absolute https URL without credentials, spaces or control characters; else null.
     */
    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '' || strlen($url) > self::URL_MAX_LENGTH || preg_match('/[\x00-\x20\x7F\p{Z}\p{Cc}]/u', $url) !== 0) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '' || strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
